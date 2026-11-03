<?php

namespace HelgeSverre\SlackCache;

use Illuminate\Cache\RetrievesMultipleKeys;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Carbon;

/**
 * A Laravel cache store persisted to Slack. One message per key.
 */
class SlackStore implements LockProvider, Store
{
    use RetrievesMultipleKeys;

    /**
     * @param  array<int, class-string>|bool|null  $serializableClasses
     */
    public function __construct(
        protected Keyspace $keyspace,
        protected string $prefix = '',
        protected array|bool|null $serializableClasses = null,
    ) {}

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

        $this->keyspace->write($this->prefix.$key, $this->serialize($value), $this->expiresAt($seconds), $this->preview($value));

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

            $this->keyspace->write($key, $this->serialize($value), $seconds > 0 ? $this->expiresAt($seconds) : null, $this->preview($value));

            return true;
        });
    }

    public function increment($key, $value = 1): int|bool
    {
        $key = $this->prefix.$key;

        return $this->keyspace->atomically($key, function () use ($key, $value) {
            $current = $this->keyspace->get($key);

            if ($current !== null && ! preg_match('/^-?(0|[1-9][0-9]*)$/', $current->value)) {
                return false;
            }

            $number = ($current === null ? 0 : (int) $current->value) + (int) $value;

            // Like Redis INCR, keep the key's TTL.
            $this->keyspace->write($key, (string) $number, $current?->expireAt);

            return $number;
        });
    }

    public function decrement($key, $value = 1): int|bool
    {
        return $this->increment($key, -$value);
    }

    public function forever($key, $value): bool
    {
        $this->keyspace->write($this->prefix.$key, $this->serialize($value), null, $this->preview($value));

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

            $this->keyspace->write($key, $current->value, $this->expiresAt($seconds), $this->preview($this->unserialize($current->value)));

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
        return new SlackLock($this, $name, $seconds, $owner);
    }

    public function restoreLock($name, $owner): SlackLock
    {
        return $this->lock($name, 0, $owner);
    }

    public function keyspace(): Keyspace
    {
        return $this->keyspace;
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

    protected function serialize(mixed $value): string
    {
        // Numbers are stored as plain text, like the Redis store, so INCR-style counters stay readable in Slack.
        if (is_int($value) || (is_numeric($value) && is_finite((float) $value))) {
            return (string) $value;
        }

        return serialize($value);
    }

    protected function unserialize(string $value): mixed
    {
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
