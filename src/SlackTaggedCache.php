<?php

namespace HelgeSverre\SlackCache;

use Illuminate\Cache\TaggedCache;

/**
 * A tagged cache that, on flush, also deletes the messages of keys stored under exactly these tags.
 *
 * Keys stored under a larger set of tags become unreachable too, as with every Laravel tagged cache,
 * but their messages stay until they expire, are reaped, or the free plan evicts them.
 */
class SlackTaggedCache extends TaggedCache
{
    public function flush(): bool
    {
        $store = $this->getStore();

        if ($store instanceof SlackStore) {
            $prefix = $store->getPrefix().sha1($this->tags->getNamespace()).':';
            $store->keyspace()->deleteWhere(fn (string $key) => str_starts_with($key, $prefix));
        }

        return parent::flush();
    }
}
