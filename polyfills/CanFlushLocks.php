<?php

namespace Illuminate\Contracts\Cache;

// Laravel 13 added this interface. On Laravel 12 SlackStore still implements it, so declare it there.
if (! interface_exists(CanFlushLocks::class)) {
    interface CanFlushLocks
    {
        public function flushLocks(): bool;

        public function hasSeparateLockStore(): bool;
    }
}
