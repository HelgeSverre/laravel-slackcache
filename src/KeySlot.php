<?php

namespace HelgeSverre\SlackCache;

/**
 * Redis Cluster's hash slots: CRC16 (XMODEM) mod 16384, honouring {hash tags}.
 */
final class KeySlot
{
    public const int SLOTS = 16384;

    public static function for(string $key): int
    {
        $start = strpos($key, '{');

        if ($start !== false) {
            $end = strpos($key, '}', $start + 1);

            if ($end !== false && $end > $start + 1) {
                $key = substr($key, $start + 1, $end - $start - 1);
            }
        }

        return self::crc16($key) % self::SLOTS;
    }

    /**
     * Which of `$shards` channels a key lives in. Contiguous slot ranges, like a fresh cluster.
     */
    public static function shard(string $key, int $shards): int
    {
        return intdiv(self::for($key) * $shards, self::SLOTS);
    }

    public static function crc16(string $data): int
    {
        $crc = 0;
        $length = strlen($data);

        for ($i = 0; $i < $length; $i++) {
            $crc ^= ord($data[$i]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return $crc;
    }
}
