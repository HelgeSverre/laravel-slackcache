<?php

namespace HelgeSverre\SlackCache\Tests;

use HelgeSverre\SlackCache\Pulse\SlackCacheRecorder;
use HelgeSverre\SlackCache\SlackCacheServiceProvider;
use Laravel\Pulse\PulseServiceProvider;
use Livewire\LivewireServiceProvider;

class PulseTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        // The order a real app may discover them in: this package first.
        return [SlackCacheServiceProvider::class, LivewireServiceProvider::class, PulseServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('pulse.enabled', true);
        $app['config']->set('pulse.recorders', [SlackCacheRecorder::class => []]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../vendor/laravel/pulse/database/migrations');
    }
}
