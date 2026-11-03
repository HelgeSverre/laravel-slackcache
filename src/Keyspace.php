<?php

namespace HelgeSverre\SlackCache;

use Closure;
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
    ) {
        $this->namespace = 'slackcache:'.substr(sha1(implode(',', $channelNames)), 0, 12);
    }

    public function get(string $key): ?Entry
    {
        $this->ensureLoaded();

        $entry = Entry::tryFrom($this->replica->get($this->entryKey($key)));

        return $entry === null ? null : ($entry->isLive($this->now(), $this->retentionMs()) ? $entry : null);
    }

    /**
     * Post the key's new message, then delete its previous one. The channel holds one message per live key.
     */
    /**
     * @param  string|null  $preview  What people see in Slack. Defaults to the stored bytes.
     */
    public function write(string $key, string $value, ?int $expireAt, ?string $preview = null): Entry
    {
        $old = $this->get($key);
        [$text, $blocks] = Codec::encode($key, $value, $expireAt, $preview);

        $shard = $this->shardFor($key);
        $ts = $this->channels()[$shard]->post($text, $blocks);
        $entry = new Entry($key, $ts, $value, $expireAt, $shard);

        $this->remember($this->generation(), $entry);

        if ($old !== null) {
            $this->channel($old->shard)?->delete($old->ts);
        }

        return $entry;
    }

    public function remove(string $key): bool
    {
        $entry = $this->get($key);

        if ($entry === null) {
            return false;
        }

        $this->replica->forget($this->entryKey($key));
        $this->channel($entry->shard)?->delete($entry->ts);

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

        $generation = $this->generation() + 1;
        $live = 0;

        foreach ($entries as $entry) {
            if ($entry->isLive($now, $this->retentionMs())) {
                $this->remember($generation, $entry);
                $live++;
            }
        }

        $this->replica->forever($this->namespace.':generation', $generation);
        $this->markSynced();

        foreach ($stale as $entry) {
            $this->channels()[$entry->shard]->delete($entry->ts);
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
                    $channel->delete($ts);
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
        $deleted = 0;

        foreach ($this->channels() as $channel) {
            foreach (iterator_to_array($channel->messages(), false) as [$ts, $text]) {
                if (Codec::decode($text) !== null) {
                    $channel->delete($ts);
                    $deleted++;
                }
            }
        }

        $this->replica->forever($this->namespace.':generation', $this->generation() + 1);
        $this->markSynced();

        return $deleted;
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
        if (! $this->replica->has($this->namespace.':synced')) {
            $this->load();
        }
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
        $deadline = $entry->deadline($this->retentionMs());

        if ($deadline === null) {
            $this->replica->forever($key, $entry->toArray());

            return;
        }

        $seconds = (int) ceil(($deadline - $this->now()) / 1000);

        if ($seconds > 0) {
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
