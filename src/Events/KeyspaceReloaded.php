<?php

namespace HelgeSverre\SlackCache\Events;

/**
 * The replica was rebuilt from the channel history.
 */
final readonly class KeyspaceReloaded
{
    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        public array $channels,
        public int $keys,
        public int $milliseconds,
    ) {}
}
