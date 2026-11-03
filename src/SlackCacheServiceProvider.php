<?php

namespace HelgeSverre\SlackCache;

use HelgeSverre\SlackCache\Commands\InfoCommand;
use HelgeSverre\SlackCache\Commands\LoadCommand;
use HelgeSverre\SlackCache\Commands\ManifestCommand;
use HelgeSverre\SlackCache\Commands\ReapCommand;
use HelgeSverre\SlackCache\Exceptions\SlackCacheException;
use HelgeSverre\SlackCache\Slack\ChannelResolver;
use HelgeSverre\SlackCache\Slack\SlackApi;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Session\SessionManager;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SlackCacheServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('slackcache')
            ->hasCommands([
                InfoCommand::class,
                LoadCommand::class,
                ManifestCommand::class,
                ReapCommand::class,
            ]);
    }

    public function packageBooted(): void
    {
        $this->callAfterResolving('session', function (SessionManager $session) {
            $session->extend('slack', function (Application $app) {
                $config = $app->make(ConfigRepository::class);
                $store = $config->get('session.store') ?: 'slack';

                return new CacheBasedSessionHandler(
                    clone $app->make(CacheManager::class)->store($store),
                    (int) $config->get('session.lifetime', 120),
                );
            });
        });

        if (class_exists(AboutCommand::class)) {
            AboutCommand::add('SlackCache', fn () => self::about($this->app->make(ConfigRepository::class)));
        }

        $this->app->make(CacheManager::class)->extend('slack', function (Application $app, array $config) {
            /** @var CacheManager $this */
            return $this->repository(
                SlackCacheServiceProvider::createStore($app, $config, $this),
                $config,
            );
        });
    }

    /**
     * One line per store that uses the slack driver, from config alone. Slack is not called.
     *
     * @return array<string, string>
     */
    public static function about(ConfigRepository $config): array
    {
        $rows = [];

        foreach ((array) $config->get('cache.stores', []) as $name => $store) {
            if (! is_array($store) || ($store['driver'] ?? null) !== 'slack') {
                continue;
            }

            $shards = max(1, (int) ($store['shards'] ?? 1));
            $channel = '#'.ltrim((string) ($store['channel'] ?? 'slackcache'), '#');
            $retention = array_key_exists('retention_days', $store) ? $store['retention_days'] : 90;

            $rows[(string) $name] = implode(' · ', array_filter([
                $shards === 1 ? $channel : "{$channel}-1 … {$channel}-{$shards}",
                'replica: '.($store['replica'] ?? 'per-process'),
                $retention === null ? 'noeviction' : 'allkeys-slack',
                $config->get('cache.default') === $name ? 'default' : null,
            ]));
        }

        return $rows === [] ? ['Stores' => 'none'] : $rows;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function createStore(Application $app, array $config, CacheManager $cache): SlackStore
    {
        $token = $config['token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new SlackCacheException('The slack cache store needs a token. Set SLACKCACHE_TOKEN to a bot token (xoxb-…).');
        }

        $shards = max(1, (int) ($config['shards'] ?? 1));
        $channel = ltrim((string) ($config['channel'] ?? 'slackcache'), '#');
        if ($shards > 1 && ChannelResolver::looksLikeId($channel)) {
            throw new SlackCacheException('Sharding needs a channel name, not a channel ID: shards are named <channel>-1 … <channel>-N.');
        }

        $channels = $shards === 1
            ? [$channel]
            : array_map(fn (int $i) => "{$channel}-{$i}", range(1, $shards));

        $api = new SlackApi(
            $app->make(HttpFactory::class),
            $token,
            timeout: (int) ($config['timeout'] ?? 60),
            retries: (int) ($config['retries'] ?? 5),
            headers: (array) ($config['headers'] ?? []),
            baseUrl: rtrim((string) ($config['base_url'] ?? SlackApi::BASE_URL), '/').'/',
        );

        $replica = self::replica($app, $config, $cache);

        $prefix = (string) ($config['prefix'] ?? $app->make(ConfigRepository::class)->get('cache.prefix', ''));
        $lockChannel = isset($config['lock_channel']) ? ltrim((string) $config['lock_channel'], '#') : null;

        return new SlackStore(
            self::keyspace($api, $replica, $channels, $config),
            $prefix,
            $config['serializable_classes'] ?? $app->make(ConfigRepository::class)->get('cache.serializable_classes'),
            $lockChannel === null ? null : new SlackStore(self::keyspace($api, $replica, [$lockChannel], $config), $prefix),
        );
    }

    /**
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $config
     */
    protected static function keyspace(SlackApi $api, \Illuminate\Contracts\Cache\Repository $replica, array $channels, array $config): Keyspace
    {
        return new Keyspace(
            $api,
            $replica,
            $channels,
            syncInterval: array_key_exists('sync_interval', $config)
                ? ($config['sync_interval'] === null ? null : (int) $config['sync_interval'])
                : 30,
            retentionDays: array_key_exists('retention_days', $config)
                ? ($config['retention_days'] === null ? null : (int) $config['retention_days'])
                : 90,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected static function replica(Application $app, array $config, CacheManager $cache): \Illuminate\Contracts\Cache\Repository
    {
        $replica = $config['replica'] ?? null;

        if ($replica === null || $replica === 'array') {
            return new Repository(new ArrayStore);
        }

        if ($app->make(ConfigRepository::class)->get("cache.stores.{$replica}.driver") === 'slack') {
            throw new SlackCacheException("The slack cache store cannot use [{$replica}] as its replica: it is itself a Slack store. It's Slack all the way down.");
        }

        return $cache->store((string) $replica);
    }
}
