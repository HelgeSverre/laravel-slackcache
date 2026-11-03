<?php

namespace HelgeSverre\SlackCache\Events;

/**
 * Slack answered HTTP 429. The driver waits `$retryAfter` seconds, then tries again.
 */
final readonly class SlackRateLimited
{
    public function __construct(
        public string $method,
        public int $retryAfter,
        public int $attempt,
    ) {}
}
