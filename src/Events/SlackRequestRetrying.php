<?php

namespace HelgeSverre\SlackCache\Events;

/**
 * A Slack call failed with a server error or a dropped connection. The driver waits `$delay` seconds, then tries again.
 */
final readonly class SlackRequestRetrying
{
    public function __construct(
        public string $method,
        public string $reason,
        public int $attempt,
        public int $delay,
    ) {}
}
