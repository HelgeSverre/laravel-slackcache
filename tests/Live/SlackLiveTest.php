<?php

/**
 * Smoke test against a real Slack workspace. Skipped unless SLACKCACHE_LIVE_TOKEN is set.
 *
 *     SLACKCACHE_LIVE_TOKEN=xoxb-… composer test:live
 *
 * A browser session token (xoxc-…) also works when SLACKCACHE_LIVE_COOKIE holds its `d` cookie and
 * SLACKCACHE_LIVE_BASE_URL is the workspace's API URL (https://your-team.slack.com/api/).
 * Posts to SLACKCACHE_LIVE_CHANNEL (default #slackcache-smoke), creating it if needed, and flushes it at the end.
 */

use HelgeSverre\SlackCache\Codec;
use HelgeSverre\SlackCache\SlackStore;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $token = getenv('SLACKCACHE_LIVE_TOKEN');

    if (! $token) {
        $this->markTestSkipped('Set SLACKCACHE_LIVE_TOKEN to run against a real workspace.');
    }

    $cookie = getenv('SLACKCACHE_LIVE_COOKIE');

    config()->set('cache.stores.slack', [
        'driver' => 'slack',
        'token' => $token,
        'headers' => $cookie ? ['Cookie' => 'd='.rawurlencode($cookie)] : [],
        'base_url' => getenv('SLACKCACHE_LIVE_BASE_URL') ?: 'https://slack.com/api/',
        'channel' => getenv('SLACKCACHE_LIVE_CHANNEL') ?: 'slackcache-smoke',
        'replica' => 'replica',
        'sync_interval' => null,
        'timeout' => 60,
    ]);
});

it('round-trips the cache contract through a real Slack channel', function () {
    $cache = Cache::store('slack');
    /** @var SlackStore $store */
    $store = $cache->getStore();
    $keyspace = $store->keyspace();
    $channel = $keyspace->channels()[0];

    $cache->flush();

    $cache->put('smoke:greeting', 'hello from Laravel', 120);
    expect($cache->get('smoke:greeting'))->toBe('hello from Laravel');

    $cache->put('smoke:greeting', 'hello again', 120);
    $cache->forever('smoke:config', ['driver' => 'slack', 'regrets' => 0]);
    expect($cache->increment('smoke:hits'))->toBe(1)
        ->and($cache->increment('smoke:hits', 2))->toBe(3)
        ->and($cache->add('smoke:greeting', 'nope', 60))->toBeFalse();

    $lock = $cache->lock('smoke:deploy', 30);
    expect($lock->get())->toBeTrue();
    $lock->release();

    // The channel holds exactly one message per live key, readable by the original codec.
    $keys = [];
    foreach ($channel->messages() as [, $text]) {
        $keys[] = Codec::decode($text)[0] ?? null;
    }
    sort($keys);
    expect($keys)->toBe(['smoke:config', 'smoke:greeting', 'smoke:hits']);

    // A cold replica rebuilds from Slack alone.
    Cache::store('replica')->flush();
    app('cache')->forgetDriver('slack');
    $cold = Cache::store('slack');

    expect($cold->get('smoke:greeting'))->toBe('hello again')
        ->and($cold->get('smoke:config'))->toBe(['driver' => 'slack', 'regrets' => 0])
        ->and((int) $cold->get('smoke:hits'))->toBe(3);

    expect($cold->forget('smoke:hits'))->toBeTrue()
        ->and($cold->get('smoke:hits'))->toBeNull();

    $cold->flush();
    expect(iterator_to_array($channel->messages(), false))->toBeEmpty();
})->group('live');
