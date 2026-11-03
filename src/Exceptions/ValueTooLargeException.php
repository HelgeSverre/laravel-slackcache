<?php

namespace HelgeSverre\SlackCache\Exceptions;

/**
 * The key and value do not fit in one Slack message.
 */
class ValueTooLargeException extends SlackCacheException
{
    public static function forKey(string $key, int $length, int $limit): self
    {
        return new self(sprintf(
            'Cache key [%s] encodes to %s characters; one Slack message holds %s. Consider a smaller value, or a database.',
            mb_strimwidth(mb_scrub($key), 0, 80, '…'),
            number_format($length),
            number_format($limit),
        ));
    }
}
