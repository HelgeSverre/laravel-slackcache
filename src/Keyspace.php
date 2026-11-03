<?php

namespace HelgeSverre\SlackCache;

use Closure;
use HelgeSverre\SlackCache\Events\HistoryReplyFailed;
use HelgeSverre\SlackCache\Events\KeyspaceReloaded;
use HelgeSverre\SlackCache\Exceptions\SlackApiException;
use HelgeSverre\SlackCache\Exceptions\SlackCacheException;
use HelgeSverre\SlackCache\Exceptions\TextMismatchException;
use HelgeSverre\SlackCache\Exceptions\ValueTooLargeException;
use HelgeSverre\SlackCache\Slack\Channel;
use HelgeSverre\SlackCache\Slack\ChannelResolver;
use HelgeSverre\SlackCache\Slack\Owner;
use HelgeSverre\SlackCache\Slack\SlackApi;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
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

    /** @var (Closure(object): mixed)|null Dispatches a queued job. */
    protected ?Closure $dispatcher = null;

    protected string $storeName = 'slack';

    protected ?string $queueConnection = null;

    protected ?string $queue = null;

    /** Write-behind: write the replica now and Slack from a queued job. */
    protected bool $async = false;

    /** Re-sync a stale replica from a queued job instead of during the read. */
    protected bool $backgroundSync = false;

    /** The largest value stored as a Slack file, in bytes, when `files` is on. Null: values must fit in a message. */
    protected ?int $files = null;

    /** @var array{hot: int, expiring: int}|null Reaction thresholds, when reactions are on. */
    protected ?array $reactions = null;

    /** The reactions decorate() manages. Others, and other people's, are left alone. */
    public const array REACTIONS = ['fire', 'hourglass', 'skull'];

    protected readonly string $namespace;

    /** How long the replica keeps a queued write or delete whose job has not run. */
    public const int PENDING_SECONDS = 86_400;

    /** @var array<string, true> Replica locks this process holds, so atomically() can nest. */
    protected array $held = [];

    /**
     * @param  list<string>  $channelNames
     * @param  int|null  $syncInterval  Seconds before the replica re-reads the channel. Null: only on a cold replica.
     * @param  int|null  $retentionDays  Days until Slack hides a message (90 on the free plan). Null: forever.
     * @param  (Closure(): ?Dispatcher)|null  $events
     */
    public function __construct(
        protected SlackApi $api,
        protected Repository $replica,
        protected array $channelNames,
        protected ?int $syncInterval = 30,
        protected ?int $retentionDays = 90,
        protected bool $history = false,
        protected ?Closure $events = null,
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

    /**
     * Use the queue for write-behind and/or background re-syncs.
     *
     * @param  Closure(object): mixed  $dispatcher
     */
    public function useQueue(Closure $dispatcher, string $storeName, ?string $connection = null, ?string $queue = null, bool $async = false, bool $backgroundSync = false): void
    {
        $this->dispatcher = $dispatcher;
        $this->storeName = $storeName;
        $this->queueConnection = $connection;
        $this->queue = $queue;
        $this->async = $async;
        $this->backgroundSync = $backgroundSync;
    }

    /**
     * Turn reactions on: count reads, and let decorate() mark hot, expiring and expired keys.
     */
    public function useReactions(int $hot, int $expiring): void
    {
        $this->reactions = ['hot' => $hot, 'expiring' => $expiring];
    }

    /**
     * Store values too large for a message as Slack files, up to `$maxBytes`.
     */
    public function useFiles(int $maxBytes): void
    {
        $this->files = $maxBytes;
    }

    public function filesEnabled(): bool
    {
        return $this->files !== null;
    }

    public function reactionsEnabled(): bool
    {
        return $this->reactions !== null;
    }

    /**
     * Count a read of a key's current message, for the hot reaction. Counts last a day.
     */
    public function countHit(Entry $entry): void
    {
        if ($this->reactions === null) {
            return;
        }

        $key = $this->hitsKey($entry->key, $entry->ts);
        $this->replica->add($key, 0, 86_400);
        $this->replica->increment($key);
    }

    /**
     * Add and remove this token's reactions so they match each key's state, within a budget of API calls.
     *
     * @return array{added: int, removed: int}
     */
    public function decorate(int $limit = 20): array
    {
        $result = ['added' => 0, 'removed' => 0];

        if ($this->reactions === null) {
            return $result;
        }

        $now = $this->now();

        foreach ($this->channels() as $channel) {
            foreach (iterator_to_array($channel->messages(), false) as [$ts, $text, $current]) {
                $record = Codec::decode($text);

                if ($record === null) {
                    continue;
                }

                $wanted = $this->wantedReactions(new Entry($record[0], $ts, $record[1], $record[2]), $now);
                $current = array_values(array_intersect($current, self::REACTIONS));

                foreach (array_diff($wanted, $current) as $name) {
                    if ($limit <= $result['added'] + $result['removed']) {
                        return $result;
                    }

                    $channel->react($ts, $name);
                    $result['added']++;
                }

                foreach (array_diff($current, $wanted) as $name) {
                    if ($limit <= $result['added'] + $result['removed']) {
                        return $result;
                    }

                    $channel->unreact($ts, $name);
                    $result['removed']++;
                }
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    protected function wantedReactions(Entry $entry, int $now): array
    {
        if (! $entry->isLive($now, $this->retentionMs())) {
            return ['skull'];
        }

        $wanted = [];

        if ($entry->expireAt !== null && $entry->expireAt - $now <= ($this->reactions['expiring'] ?? 0) * 1000) {
            $wanted[] = 'hourglass';
        }

        if ((int) $this->replica->get($this->hitsKey($entry->key, $entry->ts), 0) >= ($this->reactions['hot'] ?? PHP_INT_MAX)) {
            $wanted[] = 'fire';
        }

        return $wanted;
    }

    protected function hitsKey(string $key, string $ts): string
    {
        return $this->namespace.':hits:'.sha1($key).':'.$ts;
    }

    public function isAsync(): bool
    {
        return $this->async;
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
     * @param  list<string>  $emoji  Slack emoji names for the preview header.
     */
    public function write(string $key, string $value, ?int $expireAt, ?string $preview = null, array $tags = [], array $emoji = []): Entry
    {
        if ($this->async) {
            return $this->writeLater($key, $value, $expireAt, $preview, $tags, $emoji);
        }

        $old = $this->get($key);
        $shard = $this->shardFor($key);

        if ($this->history && $old !== null && $old->shard === $shard && ! $this->pastHalfLife($old) && $this->fileOf($old->ts) === null
            && ($encoded = $this->encodeInline($key, $value, $expireAt, $preview, $tags, $emoji)) !== null) {
            [$text, $blocks] = $encoded;

            try {
                return $this->edit($old, $text, $blocks, new Entry($key, $old->ts, $value, $expireAt, $shard), $tags, $emoji);
            } catch (SlackApiException $e) {
                if ($e->error !== 'message_not_found') {
                    throw $e;
                }

                // Another server deleted the message since this replica last synced. Write a new one.
                $old = null;
            }
        }

        $ts = $this->post($shard, $key, $value, $expireAt, $preview, $tags, $emoji);
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
     * Write-behind: remember the value as pending and queue the Slack write.
     *
     * @param  list<string>  $tags
     * @param  list<string>  $emoji
     */
    protected function writeLater(string $key, string $value, ?int $expireAt, ?string $preview, array $tags, array $emoji = []): Entry
    {
        $this->ensureLoaded();

        // Under the key's lock, so a job committing an older write cannot overwrite this pending one.
        return $this->atomically($key, fn () => $this->queueWrite($key, $value, $expireAt, $preview, $tags, $emoji));
    }

    /**
     * @param  list<string>  $tags
     * @param  list<string>  $emoji
     */
    protected function queueWrite(string $key, string $value, ?int $expireAt, ?string $preview, array $tags, array $emoji = []): Entry
    {
        // The message this write will replace: the current entry's, or a deleted entry's not yet removed from Slack.
        $raw = $this->raw($key);
        $replaces = $raw instanceof Entry ? $raw->ts : (is_array($raw) ? $raw[1] : '');

        $seq = $this->nextSeq();
        $entry = new Entry($key, $replaces, $value, $expireAt, $this->shardFor($key), $seq, pending: true);

        foreach ($this->liveGenerations() as $generation) {
            $this->remember($generation, $entry);
        }

        $this->trackPending($key, true);
        $this->queueJob(new Jobs\WriteToSlack($this->storeName, $key, $value, $expireAt, $preview, $tags, $seq, $replaces, $emoji));

        return $entry;
    }

    /**
     * Write-behind: hide the key now and queue the Slack delete.
     */
    protected function removeLater(string $key): bool
    {
        $this->ensureLoaded();

        return $this->atomically($key, fn () => $this->queueRemove($key));
    }

    protected function queueRemove(string $key): bool
    {
        $entry = $this->get($key);

        if ($entry === null) {
            return false;
        }

        $seq = $this->nextSeq();

        $this->putTombstone($key, $entry->ts, $seq);
        $this->trackPending($key, true);
        $this->queueJob(new Jobs\DeleteFromSlack($this->storeName, $key, $seq, $entry->ts));

        return true;
    }

    /**
     * Run by WriteToSlack: post a queued write, unless something newer has happened to the key since.
     *
     * The key's lock covers the replica bookkeeping before and after the post, never the Slack call itself,
     * so a slow Slack does not hold up writers to the same key. What changed in between is reconciled after.
     *
     * @param  list<string>  $tags
     * @param  list<string>  $emoji
     */
    public function commitWrite(string $key, string $value, ?int $expireAt, ?string $preview, array $tags, int $seq, string $replaces = '', array $emoji = []): void
    {
        $plan = $this->atomically($key, function () use ($key, $seq, $replaces) {
            if ($seq <= $this->flushedSeq()) {
                return null; // Written before a flush.
            }

            $raw = $this->raw($key);

            if ($raw instanceof Entry && ! $raw->pending && $raw->seq === $seq) {
                // A retry of a job that already posted: only finish deleting the message it replaced.
                if ($replaces !== '' && $replaces !== $raw->ts) {
                    $this->deleteMessage($this->channel($raw->shard), $replaces);
                }

                $this->trackPending($key, false);

                return null;
            }

            if (($raw instanceof Entry && $raw->seq > $seq) || (is_array($raw) && $raw[2] > $seq)) {
                return null; // A newer write or delete is in charge of the key.
            }

            // The replica's record is current. If it has expired, fall back to what was known when the job was queued.
            return $raw instanceof Entry ? $raw->ts : (is_array($raw) ? $raw[1] : $replaces);
        });

        if ($plan === null) {
            return;
        }

        $replaces = $plan;
        $shard = $this->shardFor($key);
        $ts = $this->post($shard, $key, $value, $expireAt, $preview, $tags, $emoji);

        $this->atomically($key, function () use ($key, $value, $expireAt, $seq, $replaces, $shard, $ts) {
            $raw = $this->raw($key);
            $channel = $this->channel($shard);

            if ($raw instanceof Entry && $raw->seq > $seq) {
                if ($raw->pending) {
                    // A newer write is queued. Hand it this message to replace, and delete the one before.
                    $this->rememberEverywhere($raw->withTs($ts));
                    $this->deleteMessage($channel, $replaces);
                } else {
                    $this->deleteMessage($channel, $ts); // A newer write already replaced the old message.
                }

                return;
            }

            if (is_array($raw) && $raw[2] > $seq) {
                if ($raw[3]) {
                    $this->deleteMessage($channel, $ts); // The key was deleted while this write was posting.
                } else {
                    // A delete is queued. Point it at this message, and delete the one before.
                    $this->putTombstone($key, $ts, $raw[2]);
                    $this->deleteMessage($channel, $replaces);
                }

                return;
            }

            $this->rememberEverywhere(new Entry($key, $ts, $value, $expireAt, $shard, $seq));

            if ($replaces !== $ts) {
                $this->deleteMessage($channel, $replaces);
            }

            $this->trackPending($key, false);
        });
    }

    /**
     * Run by DeleteFromSlack: delete a queued delete's message, unless the key was written again since.
     */
    public function commitDelete(string $key, int $seq, string $ts = ''): void
    {
        $target = $this->atomically($key, function () use ($key, $seq, $ts) {
            if ($seq <= $this->flushedSeq()) {
                return null;
            }

            $raw = $this->raw($key);

            // A newer write took over the message to replace; it deletes it when it posts.
            if (($raw instanceof Entry && $raw->seq > $seq) || (is_array($raw) && $raw[2] > $seq)) {
                return null;
            }

            // The tombstone knows the message. If it has expired, use what was known when the job was queued.
            $target = is_array($raw) && $raw[2] === $seq ? $raw[1] : $ts;

            if ($raw instanceof Entry && $raw->ts !== $target) {
                return null; // The key was reloaded from a newer message; leave it.
            }

            $this->putTombstone($key, '', $seq, done: true);
            $this->trackPending($key, false);

            return $target;
        });

        if (is_string($target)) {
            $this->deleteMessage($this->channel($this->shardFor($key)), $target);
        }
    }

    /**
     * Post a key's message and return its timestamp. A value too large for a message is uploaded as a file
     * first, when `files` is on, and the message points to it.
     *
     * @param  list<string>  $tags
     * @param  list<string>  $emoji
     */
    protected function post(int $shard, string $key, string $value, ?int $expireAt, ?string $preview, array $tags, array $emoji): string
    {
        $channel = $this->channels()[$shard];
        $encoded = $this->encodeInline($key, $value, $expireAt, $preview, $tags, $emoji);

        if ($encoded !== null) {
            return $channel->post(...$encoded);
        }

        if (strlen($value) > (int) $this->files) {
            throw ValueTooLargeException::forFile($key, strlen($value), (int) $this->files);
        }

        $file = $channel->upload('slackcache-'.substr(sha1($key), 0, 12).'.bin', mb_strimwidth(mb_scrub($key), 0, 200, '…'), $value);

        try {
            [$text, $blocks] = Codec::encode($key, $value, $expireAt, $preview, $tags, $emoji === [] ? [] : [...$emoji, 'paperclip'], $file);
            $ts = $channel->post($text, $blocks);
        } catch (\Throwable $e) {
            $channel->deleteFile($file);

            throw $e;
        }

        $this->rememberFile($ts, $file);

        return $ts;
    }

    /**
     * The message for a value that fits in one, or null when it needs a file. Throws when it does not fit and files are off.
     *
     * @param  list<string>  $tags
     * @param  list<string>  $emoji
     * @return array{0: string, 1: list<array<string, mixed>>}|null
     */
    protected function encodeInline(string $key, string $value, ?int $expireAt, ?string $preview, array $tags, array $emoji): ?array
    {
        try {
            return Codec::encode($key, $value, $expireAt, $preview, $tags, $emoji);
        } catch (ValueTooLargeException $e) {
            if ($this->files === null) {
                throw $e;
            }

            // A key too long for even a pointer message is too long, file or not.
            Codec::encode($key, '', $expireAt, null, [], [], 'F0');

            return null;
        }
    }

    /**
     * Which file a message points to, as far as the replica knows. Recorded when posting and when reloading.
     */
    protected function fileOf(string $ts): ?string
    {
        if ($this->files === null || $ts === '') {
            return null;
        }

        $file = $this->replica->get($this->namespace.':file:'.$ts);

        return is_string($file) ? $file : null;
    }

    protected function rememberFile(string $ts, string $file): void
    {
        $retention = $this->retentionMs();

        $retention === null
            ? $this->replica->forever($this->namespace.':file:'.$ts, $file)
            : $this->replica->put($this->namespace.':file:'.$ts, $file, intdiv($retention, 1000));
    }

    /**
     * A file's bytes, or null if the file is gone or does not hold what its message says.
     *
     * @param  array{0: string, 1: string}  $file  [file ID, sha256]
     */
    protected function fetchFile(Channel $channel, array $file): ?string
    {
        try {
            $bytes = $channel->download($file[0]);
        } catch (SlackApiException) {
            return null;
        }

        return hash_equals($file[1], hash('sha256', $bytes)) ? $bytes : null;
    }

    protected function rememberEverywhere(Entry $entry): void
    {
        foreach ($this->liveGenerations() as $generation) {
            $this->remember($generation, $entry);
        }
    }

    protected function putTombstone(string $key, string $ts, int $seq, bool $done = false): void
    {
        foreach ($this->liveGenerations() as $generation) {
            $this->replica->put($this->entryKey($key, $generation), ['deleted', $ts, $seq, $done], $this->tombstoneTtl());
        }
    }

    /**
     * Run by ReloadKeyspace.
     */
    public function reloadFromQueue(): int
    {
        try {
            return $this->load();
        } finally {
            $this->replica->forget($this->namespace.':reload-queued');
        }
    }

    /**
     * The replica's record for a key in the current generation: an entry, a tombstone [deleted, ts, seq], or null.
     *
     * @return Entry|array{0: string, 1: string, 2: int, 3: bool}|null A tombstone is [deleted, ts to delete, seq, done].
     */
    protected function raw(string $key, ?int $generation = null): Entry|array|null
    {
        $data = $this->replica->get($this->entryKey($key, $generation));

        if (is_array($data) && in_array(count($data), [3, 4], true) && ($data[0] ?? null) === 'deleted') {
            return ['deleted', (string) $data[1], (int) $data[2], (bool) ($data[3] ?? false)];
        }

        return Entry::tryFrom($data);
    }

    /**
     * The keys with a queued write or delete, so a reload can carry them over even when Slack has never seen them.
     */
    protected function trackPending(string $key, bool $pending): void
    {
        $this->atomically("\0pending", function () use ($key, $pending) {
            $keys = (array) $this->replica->get($this->namespace.':pending', []);

            if ($pending) {
                $keys[$key] = true;
            } else {
                unset($keys[$key]);
            }

            $this->replica->put($this->namespace.':pending', $keys, self::PENDING_SECONDS);
        });
    }

    /**
     * Wall-clock microseconds, strictly increasing within a process. Comparable across processes and servers,
     * so the latest write wins by time, the way Slack's own timestamps decide between two messages.
     */
    protected function nextSeq(): int
    {
        static $last = 0;

        return $last = max((int) (microtime(true) * 1_000_000), $last + 1);
    }

    protected function flushedSeq(): int
    {
        return (int) $this->replica->get($this->namespace.':flushed-seq', 0);
    }

    /**
     * Queued writes and deletes exist only in the replica until their job runs: keep them for a day, whatever
     * the sync interval, unless the key's own TTL ends sooner.
     */
    protected function pendingTtl(?int $deadline): int
    {
        $seconds = $deadline === null ? self::PENDING_SECONDS : (int) ceil(($deadline - $this->now()) / 1000);

        return min($seconds, self::PENDING_SECONDS);
    }

    protected function tombstoneTtl(): int
    {
        return $this->async ? self::PENDING_SECONDS : max(3600, $this->entryTtl(null) ?? self::PENDING_SECONDS);
    }

    protected function queueJob(object $job): void
    {
        if ($this->dispatcher === null) {
            throw new SlackCacheException('Write-behind and background sync need the queue. Is illuminate/queue installed?');
        }

        if (method_exists($job, 'onConnection') && method_exists($job, 'onQueue')) {
            $job->onConnection($this->queueConnection)->onQueue($this->queue);
        }

        ($this->dispatcher)($job);
    }

    /**
     * With history on: edit the key's message in place, then post the previous value as a reply in its thread.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<string>  $tags
     * @param  list<string>  $emoji
     */
    protected function edit(Entry $old, string $text, array $blocks, Entry $entry, array $tags, array $emoji = []): Entry
    {
        $channel = $this->channels()[$old->shard];

        try {
            $channel->update($old->ts, $text, $blocks);
        } catch (TextMismatchException $e) {
            // Put the previous value back, so the message never holds a value Slack truncated.
            try {
                [$oldText, $oldBlocks] = Codec::encode($old->key, $old->value, $old->expireAt, $this->previewOf($old->value), $tags, $emoji);
                $channel->update($old->ts, $oldText, $oldBlocks);
            } catch (\Throwable) {
                // The original error is the one worth reporting.
            }

            throw $e;
        }

        foreach ($this->liveGenerations() as $generation) {
            $this->remember($generation, $entry);
        }

        try {
            $channel->reply($old->ts, sprintf(
                "Previous value, replaced %s UTC%s:\n```\n%s\n```",
                Carbon::now('UTC')->format('Y-m-d H:i:s'),
                $old->expireAt === null ? '' : ' (it would have expired '.Carbon::createFromTimestampMsUTC($old->expireAt)->format('Y-m-d H:i:s').' UTC)',
                mb_strimwidth($this->previewOf($old->value), 0, Codec::PREVIEW, '…'),
            ));
        } catch (\Throwable $e) {
            // The new value is stored. A missing history reply is not worth failing the write over.
            $this->dispatch(new HistoryReplyFailed($old->key, $e));
        }

        return $entry;
    }

    /**
     * With history on, a key's message may have a thread: delete it too.
     */
    protected function deleteMessage(?Channel $channel, string $ts, ?string $file = null): void
    {
        if ($channel === null || $ts === '') {
            return;
        }

        $file ??= $this->fileOf($ts);

        $this->history ? $channel->deleteThread($ts) : $channel->delete($ts);

        if ($file !== null) {
            $channel->deleteFile($file);
            $this->replica->forget($this->namespace.':file:'.$ts);
        }
    }

    /**
     * Whether a message is past half of Slack's retention period. Editing keeps a message's timestamp, and
     * Slack hides messages by creation time, so history mode reposts such keys instead, starting a new thread.
     */
    protected function pastHalfLife(Entry $entry): bool
    {
        $retention = $this->retentionMs();

        return $retention !== null && $this->now() - Entry::tsSeconds($entry->ts) * 1000 > intdiv($retention, 2);
    }

    protected function dispatch(object $event): void
    {
        if ($this->events !== null) {
            ($this->events)()?->dispatch($event);
        }
    }

    protected function previewOf(string $value): string
    {
        return $this->previewer === null ? mb_scrub($value, 'UTF-8') : ($this->previewer)($value);
    }

    public function remove(string $key): bool
    {
        if ($this->async) {
            return $this->removeLater($key);
        }

        $entry = $this->get($key);

        if ($entry === null) {
            return false;
        }

        // Delete the message first: if Slack refuses, the replica still knows where it is.
        $this->deleteMessage($this->channel($entry->shard), $entry->ts);

        foreach ($this->liveGenerations() as $generation) {
            // A tombstone, so a reload already in progress does not resurrect the key from its snapshot.
            $this->replica->put($this->entryKey($key, $generation), ['deleted', '', 0, true], $this->tombstoneTtl());
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
        $started = hrtime(true);
        $previous = $this->generation();
        $generation = $this->nextGeneration();

        // From here on, writes and deletes also go to the generation being built.
        $this->replica->put($this->namespace.':loading', $generation, 3600);

        /** @var array<string, Entry> $entries */
        $entries = [];
        $stale = [];
        /** @var array<string, array{0: string, 1: string}> $files File pointers by message ts. */
        $files = [];
        $now = $this->now();

        foreach ($this->channels() as $shard => $channel) {
            foreach ($channel->messages() as [$ts, $text]) {
                $record = Codec::decode($text);

                if ($record === null) {
                    continue;
                }

                if ($record[3] !== null) {
                    $files[$ts] = $record[3];
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

            if (isset($files[$entry->ts])) {
                $entry = $this->withFileValue($entry, $files[$entry->ts], $previous);

                if ($entry === null) {
                    continue; // The file is gone or damaged: a miss, not a wrong value.
                }
            }

            $index[] = sha1($entry->key);
            $live++;

            // A write or delete during the reload is newer than the snapshot: keep it.
            if ($this->replica->has($this->entryKey($entry->key, $generation))) {
                continue;
            }

            // So is a queued write or delete that has not reached Slack yet.
            $queued = $this->raw($entry->key, $previous);

            if ($queued instanceof Entry && $queued->pending) {
                $this->remember($generation, $queued);
            } elseif (is_array($queued) && $queued[2] > 0 && ! $queued[3]) {
                $this->replica->put($this->entryKey($entry->key, $generation), $queued, $this->tombstoneTtl());
            } elseif ($queued instanceof Entry && $queued->ts === $entry->ts) {
                // The same message: keep the write's sequence number, so a late retry of an older write stays stale.
                $this->remember($generation, new Entry($entry->key, $entry->ts, $entry->value, $entry->expireAt, $entry->shard, $queued->seq));
            } else {
                $this->remember($generation, $entry);
            }
        }

        // Queued writes for keys Slack has never seen are not in the snapshot. Carry them over too.
        foreach (array_keys((array) $this->replica->get($this->namespace.':pending', [])) as $key) {
            $key = (string) $key;

            if (! $this->replica->has($this->entryKey($key, $generation))) {
                $queued = $this->raw($key, $previous);

                if ($queued instanceof Entry && $queued->pending) {
                    $this->remember($generation, $queued);
                } elseif (is_array($queued) && ! $queued[3]) {
                    $this->replica->put($this->entryKey($key, $generation), $queued, $this->tombstoneTtl());
                }
            }
        }

        $this->replica->forever($this->namespace.':'.$generation.':index', $index);
        $this->replica->forever($this->namespace.':generation', $generation);
        $this->replica->forget($this->namespace.':loading');
        $this->markSynced();
        $this->dropGeneration($previous);
        $this->dispatch(new KeyspaceReloaded($this->channelNames, $live, intdiv(hrtime(true) - $started, 1_000_000)));

        foreach ($stale as $entry) {
            $this->deleteMessage($this->channels()[$entry->shard], $entry->ts, $files[$entry->ts][0] ?? null);
        }

        return $live;
    }

    /**
     * Fill in the value of an entry whose message points to a file: from the previous generation when it holds
     * the same message, else by downloading the file. Null when the file cannot be read.
     *
     * @param  array{0: string, 1: string}  $file
     */
    protected function withFileValue(Entry $entry, array $file, int $previous): ?Entry
    {
        $this->rememberFile($entry->ts, $file[0]);

        $known = $this->raw($entry->key, $previous);

        if ($known instanceof Entry && $known->ts === $entry->ts && hash_equals($file[1], hash('sha256', $known->value))) {
            $value = $known->value;
        } else {
            $value = $this->fetchFile($this->channels()[$entry->shard], $file);
        }

        return $value === null ? null : new Entry($entry->key, $entry->ts, $value, $entry->expireAt, $entry->shard);
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
                    $this->deleteMessage($channel, $ts, $record[3][0] ?? null);
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
        // Queued writes from before the flush must not bring their keys back.
        $this->replica->forever($this->namespace.':flushed-seq', $this->nextSeq());

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
                    $this->deleteMessage($channel, $ts, $record[3][0] ?? null);
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
     * Values stored as files are not downloaded: their entries hold ''.
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
     * The replica's view of the keyspace, without calling Slack: whether it is fresh, its generation, and how
     * many keys have a queued write or delete waiting for a queue worker.
     *
     * @return array{synced: bool, generation: int, pending: int}
     */
    public function status(): array
    {
        return [
            'synced' => $this->replica->has($this->namespace.':synced'),
            'generation' => $this->generation(),
            'pending' => count((array) $this->replica->get($this->namespace.':pending', [])),
        ];
    }

    /** Slack's limit for a channel topic, in characters. */
    public const int TOPIC_LENGTH = 250;

    /**
     * Counts per channel, read from the channel history: live keys, keys that expire within `$soon` seconds,
     * and keys without a TTL.
     *
     * @return list<array{channel: Channel, keys: int, expiring: int, forever: int}>
     */
    public function summary(int $soon = 3600): array
    {
        $now = $this->now();
        $summary = array_map(fn (Channel $channel) => ['channel' => $channel, 'keys' => 0, 'expiring' => 0, 'forever' => 0], $this->channels());

        foreach ($this->scan() as $entry) {
            $row = &$summary[$entry->shard];
            $row['keys']++;

            if ($entry->expireAt === null) {
                $row['forever']++;
            } elseif ($entry->expireAt - $now <= $soon * 1000) {
                $row['expiring']++;
            }

            unset($row);
        }

        return $summary;
    }

    /**
     * Set each channel's topic to a summary of what it holds. A topic that has not changed is left alone, so
     * running this often costs one conversations.info call per channel, plus the history scan.
     *
     * @param  (Closure(array{channel: Channel, keys: int, expiring: int, forever: int}, int, int): string)|null  $format  Receives the row, the shard number (1-based) and the shard count.
     * @return int The number of topics changed.
     */
    public function updateTopics(?Closure $format = null, bool $keepNotice = false): int
    {
        $format ??= self::defaultTopic(...);
        $summary = $this->summary();
        $changed = 0;

        foreach ($summary as $i => $row) {
            $topic = mb_strimwidth($format($row, $i + 1, count($summary)), 0, self::TOPIC_LENGTH, '…');

            if ($row['channel']->topic() === $topic) {
                continue;
            }

            $row['channel']->setTopic($topic, $keepNotice);
            $changed++;
        }

        return $changed;
    }

    /**
     * @param  array{channel: Channel, keys: int, expiring: int, forever: int}  $row
     */
    public static function defaultTopic(array $row, int $shard, int $shards): string
    {
        $plural = fn (int $n, string $one, string $many) => number_format($n).' '.($n === 1 ? $one : $many);

        return implode(' · ', array_filter([
            $shards > 1 ? "Shard {$shard} of {$shards}" : null,
            $plural($row['keys'], 'key', 'keys'),
            $row['expiring'] > 0 ? $plural($row['expiring'], 'expires', 'expire').' within the hour' : null,
            $row['forever'] > 0 ? $plural($row['forever'], 'never expires', 'never expire') : null,
            'Cache storage. Please do not chat here; it is not that kind of channel.',
        ]));
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
        $name = $this->namespace.':lock:'.sha1($key);

        // Reentrant within a process: on the sync queue, a write job runs inside the write that queued it.
        if (! $store instanceof LockProvider || isset($this->held[$name])) {
            return $callback();
        }

        return $store->lock($name, 10)->block(10, function () use ($name, $callback) {
            $this->held[$name] = true;

            try {
                return $callback();
            } finally {
                unset($this->held[$name]);
            }
        });
    }

    public function api(): SlackApi
    {
        return $this->api;
    }

    public function replica(): Repository
    {
        return $this->replica;
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

        // Stale, not cold: keep serving the replica and let a queued job re-read the channel.
        if ($this->backgroundSync && $this->replica->has($this->namespace.':generation')) {
            if ($this->replica->add($this->namespace.':reload-queued', true, max(60, (int) $this->syncInterval))) {
                try {
                    $this->queueJob(new Jobs\ReloadKeyspace($this->storeName));
                } catch (\Throwable) {
                    // The queue is down. Serve the replica; the next stale read tries again.
                    $this->replica->forget($this->namespace.':reload-queued');
                }
            }

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

        // With background re-syncs the next reload can be late, by as long as the queue is slow.
        $cap = max(2 * $this->syncInterval + 60, $this->backgroundSync ? 3600 : 0);

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
        $seconds = $entry->pending
            ? $this->pendingTtl($entry->deadline($this->retentionMs()))
            : $this->entryTtl($entry->deadline($this->retentionMs()));

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
