<?php

namespace HelgeSverre\SlackCache\Commands;

use HelgeSverre\SlackCache\Exceptions\SlackCacheException;
use HelgeSverre\SlackCache\SlackStore;
use Illuminate\Support\Facades\Cache;

trait ResolvesSlackStore
{
    protected function slackStore(): SlackStore
    {
        $option = $this->option('store');
        $name = is_string($option) && $option !== '' ? $option : 'slack';
        $store = Cache::store($name)->getStore();

        if (! $store instanceof SlackStore) {
            throw new SlackCacheException("Cache store [{$name}] does not use the slack driver.");
        }

        return $store;
    }
}
