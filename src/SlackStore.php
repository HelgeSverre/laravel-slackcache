<?php

namespace HelgeSverre\SlackCache;

use Illuminate\Cache\RetrievesMultipleKeys;
use Illuminate\Cache\TaggableStore;
use Illuminate\Cache\TaggedCache;
use Illuminate\Cache\TagSet;
use Illuminate\Contracts\Cache\CanFlushLocks;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter as Encrypter;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A Laravel cache store persisted to Slack. One message per key.
 */
class SlackStore extends TaggableStore implements CanFlushLocks, LockProvider
{
    use RetrievesMultipleKeys;

    /**
     * The tags of the tagged cache this instance writes for, shown in the Slack preview.
     *
     * @var list<string>
     */
    protected array $tags = [];

    /** Whether this instance writes lock keys, for the preview's emoji. */
    protected bool $writesLocks = false;

    /** Marks a value compressed with zlib (raw DEFLATE). */
    public const string COMPRESSED = 'SC1Z:';

    /** Marks a value encrypted with Laravel's encrypter. */
    public const string ENCRYPTED = 'SC1E:';

    /**
     * @param  array<int, class-string>|bool|null  $serializableClasses
     */
    public function __construct(
        protected Keyspace $keyspace,
        protected string $prefix = '',
        protected array|bool|null $serializableClasses = null,
        protected ?SlackStore $lockStore = null,
        protected ?Encrypter $encrypter = null,
        protected bool $compress = false,
        protected bool $emoji = false,
    ) {
        $keyspace->previewUsing(fn (string $value) => $this->preview($this->unserialize($value)));
    }

    /**
     * Begin executing a new tags operation.
     *
     * @param  mixed  $names
     */
    public function tags($names): TaggedCache
    {
        $names = array_values(array_map('strval', is_array($names) ? $names : func_get_args()));

        $writer = clone $this;
        $writer->tags = $names;

        return new SlackTaggedCache($writer, new TagSet($this, $names));
    }

    /**
     * Laravel 4.0 called tags "sections". Laravel 4.1 renamed them. This store remembers.
     */
    public function section(string $name): TaggedCache
    {
        return $this->tags([$name]);
    }

    public function get($key): mixed
    {
        $entry = $this->keyspace->get($this->prefix.$key);

        return $entry === null ? null : $this->unserialize($entry->value);
    }

    public function put($key, $value, $seconds): bool
    {
        if ($seconds <= 0) {
            return $this->forget($key);
        }

        $this->keyspace->write($this->prefix.$key, $stored = $this->serialize($value), $this->expiresAt($seconds), $this->preview($value), $this->tags, $this->emojiFor(true, $stored));

        return true;
    }

    /**
     * Store an item only if the key is missing. Used by cache locks.
     */
    public function add(string $key, mixed $value, int $seconds): bool
    {
        $key = $this->prefix.$key;

        return $this->keyspace->atomically($key, function () use ($key, $value, $seconds) {
            if ($this->keyspace->get($key) !== null) {
                return false;
            }

            $this->keyspace->write($key, $stored = $this->serialize($value), $seconds > 0 ? $this->expiresAt($seconds) : null, $this->preview($value), $this->tags, $this->emojiFor($seconds > 0, $stored));

            return true;
        });
    }

    public function increment($key, $value = 1): int|bool
    {
        $key = $this->prefix.$key;

        return $this->keyspace->atomically($key, function () use ($key, $value) {
            $current = $this->keyspace->get($key);

            $plain = $current === null ? '0' : $this->decode($current->value);

            if ($plain === null || ! preg_match('/^-?(0|[1-9][0-9]*)$/', $plain)) {
                return false;
            }

            $number = (int) $plain + (int) $value;

            // Like Redis INCR, keep the key's TTL.
            $this->keyspace->write($key, $stored = $this->encode((string) $number), $current?->expireAt, $this->preview($number), $this->tags, $this->emojiFor($current?->expireAt !== null, $stored));

            return $number;
        });
    }

    public function decrement($key, $value = 1): int|bool
    {
        return $this->increment($key, -$value);
    }

    public function forever($key, $value): bool
    {
        $this->keyspace->write($this->prefix.$key, $stored = $this->serialize($value), null, $this->preview($value), $this->tags, $this->emojiFor(false, $stored));

        return true;
    }

    public function touch($key, $seconds): bool
    {
        $key = $this->prefix.$key;

        return $this->keyspace->atomically($key, function () use ($key, $seconds) {
            $current = $this->keyspace->get($key);

            if ($current === null) {
                return false;
            }

            $this->keyspace->write($key, $current->value, $this->expiresAt($seconds), $this->preview($this->unserialize($current->value)), $this->tags, $this->emojiFor(true, $current->value));

            return true;
        });
    }

    public function forget($key): bool
    {
        return $this->keyspace->remove($this->prefix.$key);
    }

    /**
     * Delete every cache message in the channel. Rate-limited by Slack; on a large keyspace this takes minutes.
     */
    public function flush(): bool
    {
        $this->keyspace->flush();

        return true;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function setPrefix(string $prefix): void
    {
        $this->prefix = $prefix;
    }

    public function lock($name, $seconds = 0, $owner = null): SlackLock
    {
        $writer = clone ($this->lockStore ?? $this);
        $writer->writesLocks = true;

        return new SlackLock($writer, $name, $seconds, $owner);
    }

    /**
     * Whether locks live in their own channel (`lock_channel`).
     */
    public function hasSeparateLockStore(): bool
    {
        return $this->lockStore !== null;
    }

    /**
     * Delete every lock message in the lock channel. Only possible when locks have a channel of their own.
     */
    public function flushLocks(): bool
    {
        if ($this->lockStore === null) {
            throw new RuntimeException('Flushing locks needs a separate lock channel. Set lock_channel on the slack cache store.');
        }

        $this->lockStore->keyspace()->flush();

        return true;
    }

    public function restoreLock($name, $owner): SlackLock
    {
        return $this->lock($name, 0, $owner);
    }

    /**
     * The store that holds locks when `lock_channel` is set.
     */
    public function lockStore(): ?SlackStore
    {
        return $this->lockStore;
    }

    public function keyspace(): Keyspace
    {
        return $this->keyspace;
    }

    /**
     * Slack emoji names for the preview header, when `emoji` is on: what kind of key this is, at a glance.
     *
     * @return list<string>
     */
    protected function emojiFor(bool $expires, string $stored = ''): array
    {
        if (! $this->emoji) {
            return [];
        }

        $emoji = [$this->writesLocks ? 'lock' : ($expires ? 'hourglass_flowing_sand' : 'infinity')];

        if ($this->tags !== []) {
            $emoji[] = 'label';
        }

        $plain = $stored;

        if (str_starts_with($stored, self::ENCRYPTED)) {
            $emoji[] = 'closed_lock_with_key';

            try {
                $plain = $this->encrypter?->decryptString(substr($stored, strlen(self::ENCRYPTED))) ?? '';
            } catch (DecryptException) {
                $plain = '';
            }
        }

        if (str_starts_with($plain, self::COMPRESSED)) {
            $emoji[] = 'compression';
        }

        return $emoji;
    }

    protected function expiresAt(int $seconds): int
    {
        return (int) Carbon::now()->getTimestampMs() + $seconds * 1000;
    }

    /**
     * A human-readable rendering of a value for the Slack preview. The message text keeps the exact serialized bytes.
     */
    protected function preview(mixed $value): string
    {
        if ($this->encrypter !== null) {
            return '(encrypted)';
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value) || $value === null) {
            return var_export($value, true);
        }

        if (is_array($value) || $value instanceof \JsonSerializable) {
            $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

            if ($json !== false) {
                return $json;
            }
        }

        return is_object($value) ? $value::class.' (serialized)' : get_debug_type($value);
    }

    /**
     * Serialize a value into the bytes stored in Slack: compressed and encrypted when configured.
     */
    protected function serialize(mixed $value): string
    {
        // Numbers are stored as plain text, like the Redis store, so INCR-style counters stay readable in Slack.
        if (is_int($value) || (is_numeric($value) && is_finite((float) $value))) {
            return $this->encode((string) $value);
        }

        return $this->encode(serialize($value));
    }

    /**
     * Compress, then encrypt. Compression is kept only when it makes the value smaller.
     */
    protected function encode(string $plain): string
    {
        if ($this->compress) {
            $compressed = gzdeflate($plain, 6);

            if ($compressed !== false && strlen($compressed) + strlen(self::COMPRESSED) < strlen($plain)) {
                $plain = self::COMPRESSED.$compressed;
            }
        }

        return $this->encrypter === null ? $plain : self::ENCRYPTED.$this->encrypter->encryptString($plain);
    }

    /**
     * Undo encode(). Markers are honoured whatever the current settings, so turning an option off never strands values.
     * Returns null for a value that cannot be decrypted or inflated (a rotated key, say): a cache miss.
     */
    protected function decode(string $stored): ?string
    {
        if (str_starts_with($stored, self::ENCRYPTED)) {
            if ($this->encrypter === null) {
                return null;
            }

            try {
                $stored = $this->encrypter->decryptString(substr($stored, strlen(self::ENCRYPTED)));
            } catch (DecryptException) {
                return null;
            }
        }

        if (str_starts_with($stored, self::COMPRESSED)) {
            $inflated = @gzinflate(substr($stored, strlen(self::COMPRESSED)));

            return $inflated === false ? null : $inflated;
        }

        return $stored;
    }

    protected function unserialize(string $value): mixed
    {
        $value = $this->decode($value);

        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return $value;
        }

        $result = $this->serializableClasses !== null
            ? @unserialize($value, ['allowed_classes' => $this->serializableClasses])
            : @unserialize($value);

        // A value written by something other than PHP (redis-cli via the Python server, say) is returned as-is.
        if ($result === false && $value !== serialize(false)) {
            return $value;
        }

        return $result;
    }
}
