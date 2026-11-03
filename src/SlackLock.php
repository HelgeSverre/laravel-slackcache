<?php

namespace HelgeSverre\SlackCache;

use Illuminate\Cache\CacheLock;

/**
 * A cache lock that always acquires through SlackStore::add(), which is atomic per replica.
 * Laravel's CacheLock falls back to a non-atomic get-then-put for locks without a TTL.
 */
class SlackLock extends CacheLock
{
    /** @var SlackStore */
    protected $store;

    public function acquire(): bool
    {
        return $this->store->add($this->name, $this->owner, $this->seconds);
    }
}
