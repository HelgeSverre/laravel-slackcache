<?php

namespace HelgeSverre\SlackCache;

use Closure;
use HelgeSverre\SlackCache\Exceptions\SlackCacheException;
use HelgeSverre\SlackCache\Slack\Channel;
use HelgeSverre\SlackCache\Slack\ChannelResolver;
use HelgeSverre\SlackCache\Slack\Owner;
use HelgeSverre\SlackCache\Slack\SlackApi;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;

/**
 * The keyspace: persisted to Slack, one message per key, and read from a local replica.
 *
 * Redis keeps data in RAM and persists it to an append-only file. SlackCache keeps data in a
 * replica (any other Laravel cache store) and persists it to a Slack channel. Every write waits
 * for Slack to accept the message. Reads come from the replica. When the replica is cold, or
 * older than the sync interval, the keyspace is rebuilt from the channel history, the way Redis
 * replays its append-only file on startup.
 */
class Keyspace
{
    /** @var list<Channel>|null */
    protected ?array $channels = null;

    /** @var (Closure(string): string)|null */
    protected ?Closure $previewer = null;

    protected readonly string $namespace;

    /**
     * @param  list<string>  $channelNames
     * @param  int|null  $syncInterval  Seconds before the replica re-reads the channel. Null: only on a cold replica.
     * @param  int|null  $retentionDays  Days until Slack hides a message (90 on the free plan). Null: forever.
     */
    public function __construct(
        protected SlackApi $api,
        protected Repository $replica,
        protected array $channelNames,
        protected ?int $syncInterval = 30,
        protected ?int $retentionDays = 90,
        protected bool $history = false,
    ) {
        $this->namespace = 'slackcache:'.substr(sha1(implode(',', $channelNames)), 0, 12);
    }

    /**
     * Render stored bytes for people, for the history thread. Set by the store, which knows how values are serialized.
     *
     * @param  Closure(string): string  $previewer
     */
    public function previewUsing(Closure $previewer): void
    {
        $this->previewer = $previewer;
    }

    public function get(string $key): ?Entry
    {
        $this->ensureLoaded();

        $entry = Entry::tryFrom($this->replica->get($this->entryKey($key)));

        return $entry === null ? null : ($entry->isLive($this->now(), $this->retentionMs()) ? $entry : null);
    }

    /**
     * Post the key's new message, then delete its previous one. The channel holds one message per live key.
     *
     * @param  string|null  $preview  What people see in Slack. Defaults to the stored bytes.
     * @param  list<string>  $tags  Cache tags, shown in the preview.
     */
    public function write(string $key, string $value, ?int $expireAt, ?string $preview = null, array $tags = []): Entry
    {
        $old = $this->get($key);
        [$text, $blocks] = Codec::encode($key, $value, $expireAt, $preview, $tags);

        $shard = $this->shardFor($key);

        if ($this->history && $old !== null && $old->shard === $shard) {
            return $this->edit($old, $text, $blocks, new Entry($key, $old->ts, $value, $expireAt, $shard), $tags);
        }

        $ts = $this->channels()[$shard]->post($text, $blocks);
        $entry = new Entry($key, $ts, $value, $expireAt, $shard);

        foreach ($this->liveGenerations() as $generation) {
            $this->remember($generation, $entry);
        }

        if ($old !== null) {
            $this->deleteMessage($this->channel($old->shard), $old->ts);
        }

        return $entry;
    }

    /**
     * With history on: edit the key's message in place, then post the previous value as a reply in its thread.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<string>  $tags
     */
    protected function edit(Entry $old, string $text, array $blocks, Entry $entry, array $tags): Entry
    {
        $channel = $this->channels()[$old->shard];

        try {
            $channel->update($old->ts, $text, $blocks);
        } catch (SlackCacheException $e) {
            // Put the previous value back, so the message never holds a value Slack truncated.
            [$oldText, $oldBlocks] = Codec::encode($old->key, $old->value, $old->expireAt, $this->previewOf($old->value), $tags);
            $channel->update($old->ts, $oldText, $oldBlocks);

            throw $e;
        }

        foreach ($this->liveGenerations() as $generation) {
            $this->remember($generation, $entry);
        }

        $channel->reply($old->ts, sprintf(
            "Previous value, replaced %s UTC%s:\n```\n%s\n```",
            Carbon::now('UTC')->format('Y-m-d H:i:s'),
            $old->expireAt === null ? '' : ' (it would have expired '.Carbon::createFromTimestampMsUTC($old->expireAt)->format('Y-m-d H:i:s').' UTC)',
            mb_strimwidth($this->previewOf($old->value), 0, Codec::PREVIEW, '…'),
        ));

        return $entry;
    }

    /**
     * With history on, a key's message may have a thread: delete it too.
     */
    protected function deleteMessage(?Channel $channel, string $ts): void
    {
        if ($channel === null) {
            return;
        }

        $this->history ? $channel->deleteThread($ts) : $channel->delete($ts);
    }

    protected function previewOf(string $value): string
    {
        return $this->previewer === null ? mb_scrub($value, 'UTF-8') : ($this->previewer)($value);
    }

    public function remove(string $key): bool
    {
        $entry = $this->get($key);

        if ($entry === null) {
            return false;
        }

        // Delete the message first: if Slack refuses, the replica still knows where it is.
        $this->deleteMessage($this->channel($entry->shard), $entry->ts);

        foreach ($this->liveGenerations() as $generation) {
            // A tombstone, so a reload already in progress does not resurrect the key from its snapshot.
            $this->replica->put($this->entryKey($key, $generation), 'deleted', $this->entryTtl(null) ?? 3600);
        }

        return true;
    }

    /**
     * Rebuild the replica from the channel history. When two messages hold the same key, the one
     * with the later Slack timestamp wins and the other is deleted.
     *
     * @return int The number of live keys loaded.
     */
    public function load(): int
    {
        return $this->exclusively('load', fn () => $this->reload());
    }

    protected function reload(): int
    {
        $previous = $this->generation();
        $generation = $this->nextGeneration();

        // From here on, writes and deletes also go to the generation being built.
        $this->replica->put($this->namespace.':loading', $generation, 3600);

        /** @var array<string, Entry> $entries */
        $entries = [];
        $stale = [];
        $now = $this->now();

        foreach ($this->channels() as $shard => $channel) {
            foreach ($channel->messages() as [$ts, $text]) {
                $record = Codec::decode($text);

                if ($record === null) {
                    continue;
                }

                $entry = new Entry($record[0], $ts, $record[1], $record[2], $shard);
                $current = $entries[$entry->key] ?? null;

                if ($current === null) {
                    $entries[$entry->key] = $entry;
                } elseif ($entry->isNewerThan($current)) {
                    $stale[] = $current;
                    $entries[$entry->key] = $entry;
                } else {
                    $stale[] = $entry;
                }
            }
        }

        $live = 0;
        $index = [];

        foreach ($entries as $entry) {
            if (! $entry->isLive($now, $this->retentionMs())) {
                continue;
            }

            $index[] = sha1($entry->key);
            $live++;

            // A write or delete during the reload is newer than the snapshot: keep it.
            if (! $this->replica->has($this->entryKey($entry->key, $generation))) {
                $this->remember($generation, $entry);
            }
        }

        $this->replica->forever($this->namespace.':'.$generation.':index', $index);
        $this->replica->forever($this->namespace.':generation', $generation);
        $this->replica->forget($this->namespace.':loading');
        $this->markSynced();
        $this->dropGeneration($previous);

        foreach ($stale as $entry) {
            $this->deleteMessage($this->channels()[$entry->shard], $entry->ts);
        }

        return $live;
    }

    /**
     * Delete the messages of expired keys, like Redis's active expiry.
     *
     * @return int The number of messages deleted.
     */
    public function reap(int $limit = 20): int
    {
        $now = $this->now();
        $reaped = 0;

        foreach ($this->channels() as $channel) {
            foreach (iterator_to_array($channel->messages(), false) as [$ts, $text]) {
                if ($reaped >= $limit) {
                    return $reaped;
                }

                $record = Codec::decode($text);

                if ($record !== null && ! (new Entry($record[0], $ts, $record[1], $record[2]))->isLive($now, $this->retentionMs())) {
                    $this->deleteMessage($channel, $ts);
                    $reaped++;
                }
            }
        }

        return $reaped;
    }

    /**
     * Delete every cache message in every channel. Other messages in the channel are left alone.
     */
    public function flush(): int
    {
        $deleted = $this->deleteWhere(fn () => true);

        $this->exclusively('load', function () {
            $previous = $this->generation();
            $this->replica->forever($this->namespace.':generation', $this->nextGeneration());
            $this->markSynced();
            $this->dropGeneration($previous);
        });

        return $deleted;
    }

    /**
     * Delete the messages of keys that match a predicate, scanning every channel, and forget them in the replica.
     *
     * @param  callable(string): bool  $matches
     * @return int The number of messages deleted.
     */
    public function deleteWhere(callable $matches): int
    {
        $deleted = 0;

        foreach ($this->channels() as $channel) {
            foreach (iterator_to_array($channel->messages(), false) as [$ts, $text]) {
                $record = Codec::decode($text);

                if ($record !== null && $matches($record[0])) {
                    $this->deleteMessage($channel, $ts);
                    $deleted++;

                    foreach ($this->liveGenerations() as $generation) {
                        $this->replica->forget($this->entryKey($record[0], $generation));
                    }
                }
            }
        }

        return $deleted;
    }

    /**
     * Every live key, read from the channel history rather than the replica. Sorted by key.
     *
     * @return list<Entry>
     */
    public function scan(): array
    {
        /** @var array<string, Entry> $entries */
        $entries = [];
        $now = $this->now();

        foreach ($this->channels() as $shard => $channel) {
            foreach ($channel->messages() as [$ts, $text]) {
                $record = Codec::decode($text);

                if ($record === null) {
                    continue;
                }

                $entry = new Entry($record[0], $ts, $record[1], $record[2], $shard);
                $current = $entries[$entry->key] ?? null;

                if ($current === null || $entry->isNewerThan($current)) {
                    $entries[$entry->key] = $entry;
                }
            }
        }

        $live = array_filter($entries, fn (Entry $entry) => $entry->isLive($now, $this->retentionMs()));
        ksort($live, SORT_STRING);

        return array_values($live);
    }

    /**
     * @return array<string, int|string|null>
     */
    public function info(): array
    {
        return [
            'slack_channels' => implode(',', array_map(fn (Channel $c) => '#'.$c->name, $this->channels())),
            'cluster_shards' => count($this->channelNames),
            'maxmemory_policy' => $this->retentionDays === null ? 'noeviction' : 'allkeys-slack',
            'retention_days' => $this->retentionDays,
            'aof_fsync' => 'slack',
            'sync_interval' => $this->syncInterval,
            'replica_generation' => $this->generation(),
        ];
    }

    /**
     * Run a callback without letting a write from another process land between its read and its write.
     *
     * Atomic per replica: it uses the replica's lock when the replica provides one.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function atomically(string $key, Closure $callback): mixed
    {
        $store = $this->replica->getStore();

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        return $store->lock($this->namespace.':lock:'.sha1($key), 10)->block(10, $callback);
    }

    public function shardFor(string $key): int
    {
        return KeySlot::shard($key, count($this->channelNames));
    }

    /**
     * @return list<Channel>
     */
    public function channels(): array
    {
        if ($this->channels !== null) {
            return $this->channels;
        }

        $resolved = ChannelResolver::validate($this->replica->get($this->namespace.':channels'), count($this->channelNames));

        if ($resolved === null) {
            $resolved = (new ChannelResolver($this->api))->resolve($this->channelNames);
            $this->replica->forever($this->namespace.':channels', $resolved);
        }

        $owner = new Owner($resolved['owner']['bot_id'], $resolved['owner']['user_id']);
        $channels = [];

        foreach ($resolved['channels'] as $channel) {
            $channels[] = new Channel($this->api, $channel['id'], $channel['name'], $owner);
        }

        return $this->channels = $channels;
    }

    protected function channel(int $shard): ?Channel
    {
        return $this->channels()[$shard] ?? null;
    }

    protected function ensureLoaded(): void
    {
        if ($this->replica->has($this->namespace.':synced')) {
            return;
        }

        // One process reloads; the others wait for it, then find the replica fresh.
        $this->exclusively('load', function () {
            if (! $this->replica->has($this->namespace.':synced')) {
                $this->reload();
            }
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function exclusively(string $name, Closure $callback): mixed
    {
        $store = $this->replica->getStore();

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        // A reload reads the whole channel history at Slack's Tier 3 rate; give it time.
        return $store->lock($this->namespace.':'.$name, 600)->block(600, $callback);
    }

    /**
     * The generation readers use, plus the one a reload is building, if any.
     *
     * @return list<int>
     */
    protected function liveGenerations(): array
    {
        $current = $this->generation();
        $loading = $this->replica->get($this->namespace.':loading');

        return is_int($loading) && $loading !== $current ? [$current, $loading] : [$current];
    }

    protected function nextGeneration(): int
    {
        $next = $this->replica->increment($this->namespace.':generation-counter');

        // Never reuse a number, even if the counter was evicted from the replica.
        return max((int) $next, $this->generation() + 1);
    }

    /**
     * Forget the entries a reload put in an old generation. Entries written later expire on their own (see entryTtl()).
     */
    protected function dropGeneration(int $generation): void
    {
        $index = $this->replica->pull($this->namespace.':'.$generation.':index');

        foreach (is_array($index) ? $index : [] as $hash) {
            if (is_string($hash)) {
                $this->replica->forget($this->namespace.':'.$generation.':'.$hash);
            }
        }
    }

    /**
     * How long the replica keeps an entry, in seconds. Never past the key's deadline, and, when the replica
     * re-syncs, never much past the next re-sync, which rewrites every live entry. Null: forever.
     */
    protected function entryTtl(?int $deadline): ?int
    {
        $seconds = $deadline === null ? null : (int) ceil(($deadline - $this->now()) / 1000);

        if ($this->syncInterval === null) {
            return $seconds;
        }

        $cap = 2 * $this->syncInterval + 60;

        return $seconds === null ? $cap : min($seconds, $cap);
    }

    protected function markSynced(): void
    {
        if ($this->syncInterval === null) {
            $this->replica->forever($this->namespace.':synced', true);
        } else {
            $this->replica->put($this->namespace.':synced', true, $this->syncInterval);
        }
    }

    protected function remember(int $generation, Entry $entry): void
    {
        $key = $this->entryKey($entry->key, $generation);
        $seconds = $this->entryTtl($entry->deadline($this->retentionMs()));

        if ($seconds === null) {
            $this->replica->forever($key, $entry->toArray());
        } elseif ($seconds > 0) {
            $this->replica->put($key, $entry->toArray(), $seconds);
        }
    }

    protected function generation(): int
    {
        return (int) $this->replica->get($this->namespace.':generation', 0);
    }

    protected function entryKey(string $key, ?int $generation = null): string
    {
        return $this->namespace.':'.($generation ?? $this->generation()).':'.sha1($key);
    }

    protected function retentionMs(): ?int
    {
        return $this->retentionDays === null ? null : $this->retentionDays * 86_400_000;
    }

    protected function now(): int
    {
        return (int) Carbon::now()->getTimestampMs();
    }
}
