<?php

namespace HelgeSverre\SlackCache;

use HelgeSverre\SlackCache\Slack\SlackApi;
use HelgeSverre\SlackCache\Testing\FakeSlack;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Entry points for applications that use the driver.
 */
final class SlackCache
{
    /**
     * Swap Slack for an in-memory workspace, for your application's tests.
     *
     * Every store that uses the slack driver is rebuilt against the fake, with a fresh in-process replica,
     * so no test sees another test's keys. A missing token is filled in: the fake accepts any.
     *
     *     $slack = SlackCache::fake();
     *     $this->get('/dashboard');
     *     $slack->assertStored('dashboard:stats');
     */
    public static function fake(mixed ...$options): FakeSlack
    {
        $config = app(ConfigRepository::class);
        $cache = app(CacheManager::class);
        $names = [];

        foreach ((array) $config->get('cache.stores', []) as $name => $store) {
            if (! is_array($store) || ($store['driver'] ?? null) !== 'slack') {
                continue;
            }

            $names[] = $name = (string) $name;
            $config->set("cache.stores.{$name}.replica", 'array');
            $config->set("cache.stores.{$name}.base_url", SlackApi::BASE_URL);

            if (! is_string($store['token'] ?? null) || $store['token'] === '') {
                $config->set("cache.stores.{$name}.token", 'xoxb-slackcache-fake');
            }
        }

        if ($names !== []) {
            $cache->forgetDriver($names);
        }

        $fake = FakeSlack::install(null, ...$options);

        $default = in_array($config->get('cache.default'), $names, true) ? (string) $config->get('cache.default') : ($names[0] ?? null);

        return $default === null ? $fake : $fake->forStore($default);
    }
}
