<?php

namespace HelgeSverre\SlackCache\Jobs;

use HelgeSverre\SlackCache\SlackStore;
use Illuminate\Bus\Queueable;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Write-behind: delete a forgotten key's message. Skips itself if the key was written again since.
 */
class DeleteFromSlack implements ShouldQueue
{
    use Queueable;

    /** Base64, so binary keys survive the queue's JSON payload. */
    public string $key;

    public function __construct(
        public string $store,
        string $key,
        public int $seq,
        public string $ts = '',
    ) {
        $this->key = base64_encode($key);
    }

    public function handle(CacheManager $cache): void
    {
        $store = $cache->store($this->store)->getStore();

        if ($store instanceof SlackStore) {
            $store->keyspace()->commitDelete((string) base64_decode($this->key, true), $this->seq, $this->ts);
        }
    }
}
