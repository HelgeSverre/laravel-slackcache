<?php

namespace HelgeSverre\SlackCache\Jobs;

use HelgeSverre\SlackCache\SlackStore;
use Illuminate\Bus\Queueable;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Background sync: rebuild a stale replica from the channel history while reads carry on.
 */
class ReloadKeyspace implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $store) {}

    public function handle(CacheManager $cache): void
    {
        $store = $cache->store($this->store)->getStore();

        if ($store instanceof SlackStore) {
            $store->keyspace()->reloadFromQueue();
        }
    }
}
