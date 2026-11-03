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
use Illuminate\Http\Client\Factory as HttpFactory;
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
        $this->app->make(CacheManager::class)->extend('slack', function (Application $app, array $config) {
            /** @var CacheManager $this */
            return $this->repository(
                SlackCacheServiceProvider::createStore($app, $config, $this),
                $config,
            );
        });
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

        $keyspace = new Keyspace(
            $api,
            self::replica($app, $config, $cache),
            $channels,
            syncInterval: array_key_exists('sync_interval', $config)
                ? ($config['sync_interval'] === null ? null : (int) $config['sync_interval'])
                : 30,
            retentionDays: array_key_exists('retention_days', $config)
                ? ($config['retention_days'] === null ? null : (int) $config['retention_days'])
                : 90,
        );

        return new SlackStore(
            $keyspace,
            (string) ($config['prefix'] ?? $app->make(ConfigRepository::class)->get('cache.prefix', '')),
            $config['serializable_classes'] ?? $app->make(ConfigRepository::class)->get('cache.serializable_classes'),
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
