<?php

namespace HelgeSverre\SlackCache;

/**
 * One live key: the Slack message that holds it and what it holds.
 *
 * With write-behind (`async`), an entry can be pending: its value is not in Slack yet, and `ts` is the
 * message it will replace ('' for none). `seq` orders writes so a stale queued job can tell it is stale.
 */
final readonly class Entry
{
    public function __construct(
        public string $key,
        public string $ts,
        public string $value,
        public ?int $expireAt,
        public int $shard = 0,
        public int $seq = 0,
        public bool $pending = false,
    ) {}

    public function withTs(string $ts): self
    {
        return new self($this->key, $ts, $this->value, $this->expireAt, $this->shard, $this->seq, $this->pending);
    }

    /**
     * When the key stops existing, in milliseconds: its TTL, or the moment the
     * free plan hides the message, whichever comes first.
     */
    public function deadline(?int $retentionMs): ?int
    {
        // A pending write has no message of its own yet, so Slack's retention does not apply to it.
        $horizon = $retentionMs === null || $this->pending ? null : self::tsSeconds($this->ts) * 1000 + $retentionMs;

        if ($this->expireAt === null) {
            return $horizon;
        }

        return $horizon === null ? $this->expireAt : min($this->expireAt, $horizon);
    }

    public function isLive(int $nowMs, ?int $retentionMs): bool
    {
        $deadline = $this->deadline($retentionMs);

        return $deadline === null || $deadline > $nowMs;
    }

    /**
     * Whether this entry's message is newer than another's, by Slack timestamp.
     */
    public function isNewerThan(self $other): bool
    {
        return self::compareTs($this->ts, $other->ts) > 0;
    }

    public static function compareTs(string $a, string $b): int
    {
        [$aSeconds, $aFraction] = array_pad(explode('.', $a, 2), 2, '0');
        [$bSeconds, $bFraction] = array_pad(explode('.', $b, 2), 2, '0');

        return [(int) $aSeconds, (int) $aFraction] <=> [(int) $bSeconds, (int) $bFraction];
    }

    public static function tsSeconds(string $ts): int
    {
        return (int) explode('.', $ts, 2)[0];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: int|null, 4: int, 5: int, 6: bool}
     */
    public function toArray(): array
    {
        return [$this->key, $this->ts, $this->value, $this->expireAt, $this->shard, $this->seq, $this->pending];
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: int|null, 4: int, 5?: int, 6?: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }

    /**
     * Rebuild an entry read back from the replica, or null if the data is missing or malformed.
     */
    public static function tryFrom(mixed $data): ?self
    {
        if (! is_array($data) || ! array_is_list($data) || ! in_array(count($data), [5, 7], true)) {
            return null;
        }

        [$key, $ts, $value, $expireAt, $shard] = $data;
        $seq = $data[5] ?? 0;
        $pending = $data[6] ?? false;

        if (! is_string($key) || ! is_string($ts) || ! is_string($value) || ! is_int($shard) || ($expireAt !== null && ! is_int($expireAt))
            || ! is_int($seq) || ! is_bool($pending)) {
            return null;
        }

        return new self($key, $ts, $value, $expireAt, $shard, $seq, $pending);
    }
}
