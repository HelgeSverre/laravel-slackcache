<?php

namespace HelgeSverre\SlackCache\Events;

use Throwable;

/**
 * With `history` on, a value was stored but its previous value could not be posted to the thread.
 */
final readonly class HistoryReplyFailed
{
    public function __construct(
        public string $key,
        public Throwable $exception,
    ) {}
}
