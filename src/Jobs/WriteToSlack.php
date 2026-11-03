<?php

namespace HelgeSverre\SlackCache\Jobs;

use HelgeSverre\SlackCache\SlackStore;
use Illuminate\Bus\Queueable;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Write-behind: post a cache write to Slack. Skips itself if the key changed again since.
 */
class WriteToSlack implements ShouldQueue
{
    use Queueable;

    /** Base64, so binary values survive the queue's JSON payload. */
    public string $key;

    public string $value;

    public ?string $preview;

    /**
     * @param  list<string>  $tags
     * @param  list<string>  $emoji
     */
    public function __construct(
        public string $store,
        string $key,
        string $value,
        public ?int $expireAt,
        ?string $preview,
        public array $tags,
        public int $seq,
        public string $replaces = '',
        public array $emoji = [],
    ) {
        $this->key = base64_encode($key);
        $this->value = base64_encode($value);
        $this->preview = $preview === null ? null : base64_encode($preview);
    }

    public function handle(CacheManager $cache): void
    {
        $store = $cache->store($this->store)->getStore();

        if ($store instanceof SlackStore) {
            $store->keyspace()->commitWrite(
                (string) base64_decode($this->key, true),
                (string) base64_decode($this->value, true),
                $this->expireAt,
                $this->preview === null ? null : (string) base64_decode($this->preview, true),
                $this->tags,
                $this->seq,
                $this->replaces,
                $this->emoji,
            );
        }
    }
}
