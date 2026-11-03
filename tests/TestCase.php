<?php

namespace HelgeSverre\SlackCache\Tests;

use HelgeSverre\SlackCache\SlackCacheServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SlackCacheServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.prefix', '');
        $app['config']->set('cache.stores.slack', [
            'driver' => 'slack',
            'token' => 'xoxb-test-token',
            'channel' => 'slackcache',
            'replica' => 'replica',
            'sync_interval' => 30,
            'retention_days' => 90,
        ]);
        $app['config']->set('cache.stores.replica', ['driver' => 'array', 'serialize' => true]);
    }
}
