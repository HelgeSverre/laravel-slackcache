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

it('tags, flushes tags and keeps locks in their own channel on a real workspace', function () {
    config()->set('cache.stores.slack.lock_channel', (getenv('SLACKCACHE_LIVE_CHANNEL') ?: 'slackcache-smoke').'-locks');
    $cache = Cache::store('slack');
    /** @var SlackStore $store */
    $store = $cache->getStore();
    $cache->flush();
    $store->flushLocks();

    $cache->tags(['smoke', 'people'])->put('john', 'doe', 120);
    $cache->tags(['smoke'])->put('anne', 'smith', 120);
    expect($cache->tags(['smoke', 'people'])->get('john'))->toBe('doe');

    $cache->tags(['smoke'])->flush();
    expect($cache->tags(['smoke'])->get('anne'))->toBeNull()
        ->and($cache->tags(['smoke', 'people'])->get('john'))->toBeNull();

    $lock = $cache->lock('smoke:deploy', 30);
    expect($lock->get())->toBeTrue()
        ->and($cache->lock('smoke:deploy', 30)->get())->toBeFalse()
        ->and($store->flushLocks())->toBeTrue()
        ->and($cache->lock('smoke:deploy', 30)->get())->toBeTrue();

    $store->flushLocks();
    $cache->flush();
    expect(iterator_to_array($store->keyspace()->channels()[0]->messages(), false))->toBeEmpty();
})->group('live');

it('keeps version history in threads on a real workspace', function () {
    config()->set('cache.stores.slack.history', true);
    $cache = Cache::store('slack');
    /** @var SlackStore $store */
    $store = $cache->getStore();
    $channel = $store->keyspace()->channels()[0];
    $cache->flush();

    $cache->forever('smoke:config', ['theme' => 'light']);
    $cache->forever('smoke:config', ['theme' => 'dark']);
    $cache->forever('smoke:config', ['theme' => 'solarized']);

    $messages = iterator_to_array($channel->messages(), false);
    expect($messages)->toHaveCount(1);

    // A cold replica reads the edited message.
    Cache::store('replica')->flush();
    app('cache')->forgetDriver('slack');
    expect(Cache::store('slack')->get('smoke:config'))->toBe(['theme' => 'solarized']);

    // Slack has the two previous values in the thread.
    $api = (fn () => $this->api)->call($channel);
    $replies = $api->call('conversations.replies', ['channel' => $channel->id, 'ts' => $messages[0][0]])['messages'];
    $texts = array_column(array_slice($replies, 1), 'text');

    expect($texts)->toHaveCount(2)
        ->and($texts[0])->toContain('light')
        ->and($texts[1])->toContain('dark');

    Cache::store('slack')->flush();

    // Deleting the key deleted its thread first, so Slack left no "This message was deleted." placeholder.
    $history = $api->call('conversations.history', ['channel' => $channel->id, 'limit' => 50])['messages'];
    expect(array_filter($history, fn (array $m) => ($m['subtype'] ?? null) === 'tombstone'))->toBeEmpty();
})->group('live');

it('encrypts and compresses on a real workspace', function () {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    config()->set('cache.stores.slack.encrypt', true);
    config()->set('cache.stores.slack.compress', true);
    $cache = Cache::store('slack');
    /** @var SlackStore $store */
    $store = $cache->getStore();
    $channel = $store->keyspace()->channels()[0];
    $cache->flush();

    $value = str_repeat('{"row": "a fairly repetitive payload"}, ', 800); // ~32 KB, over the uncompressed limit
    $cache->put('smoke:big-secret', $value, 120);

    [, $text] = iterator_to_array($channel->messages(), false)[0];
    expect(Codec::decode($text)[1])->toStartWith('SC1E:')
        ->and($text)->not->toContain('repetitive');

    Cache::store('replica')->flush();
    app('cache')->forgetDriver('slack');
    expect(Cache::store('slack')->get('smoke:big-secret'))->toBe($value);

    Cache::store('slack')->flush();
})->group('live');

it('passes slackcache:doctor on a real workspace', function () {
    $this->artisan('slackcache:doctor')
        ->expectsOutputToContain('All checks passed')
        ->assertSuccessful();
})->group('live');

it('writes behind and re-syncs in the background on a real workspace (sync queue)', function () {
    config()->set('queue.default', 'sync');
    config()->set('cache.stores.slack.async', true);
    config()->set('cache.stores.slack.sync', 'background');
    config()->set('cache.stores.slack.sync_interval', 5);
    $cache = Cache::store('slack');
    /** @var SlackStore $store */
    $store = $cache->getStore();
    $channel = $store->keyspace()->channels()[0];
    $cache->flush();

    $cache->put('smoke:async', 'one', 120);
    $cache->put('smoke:async', 'two', 120);
    expect($cache->increment('smoke:async-hits', 5))->toBe(5);
    $cache->forget('smoke:async-hits');

    $texts = array_map(fn ($m) => Codec::decode($m[1]), iterator_to_array($channel->messages(), false));
    expect($texts)->toHaveCount(1)
        ->and($texts[0][0])->toBe('smoke:async')
        ->and($texts[0][1])->toBe(serialize('two'));

    $this->travel(6)->seconds();
    expect(Cache::store('slack')->get('smoke:async'))->toBe('two');

    $cache->flush();
})->group('live');

it('shows status emoji in previews on a real workspace', function () {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    config()->set('cache.stores.slack.emoji', true);
    config()->set('cache.stores.slack.encrypt', true);
    config()->set('cache.stores.slack.compress', true);
    $cache = Cache::store('slack');
    /** @var SlackStore $store */
    $store = $cache->getStore();
    $channel = $store->keyspace()->channels()[0];
    $cache->flush();

    $cache->put('smoke:emoji-ttl', 'short-lived', 300);
    $cache->tags(['smoke'])->forever('smoke:emoji-forever', str_repeat('compressible ', 400));
    $cache->lock('smoke:emoji-lock', 60)->get();

    // Read the stored blocks back from Slack: it accepted and kept every emoji element.
    $api = $store->keyspace()->api();
    $history = $api->call('conversations.history', ['channel' => $channel->id, 'limit' => 20])['messages'];
    $emoji = [];

    foreach ($history as $message) {
        $key = Codec::decode($message['text'] ?? '')[0] ?? null;

        if ($key !== null) {
            $section = $message['blocks'][0]['elements'][0]['elements'];
            $emoji[$key] = array_values(array_map(fn ($e) => $e['name'], array_filter($section, fn ($e) => $e['type'] === 'emoji')));
        }
    }

    expect($emoji)->toMatchArray([
        'smoke:emoji-ttl' => ['hourglass_flowing_sand', 'closed_lock_with_key'],
        'tag:smoke:key' => ['infinity', 'closed_lock_with_key'],
        'smoke:emoji-lock' => ['lock', 'closed_lock_with_key'],
    ]);

    $forever = collect($emoji)->first(fn ($names, $key) => str_ends_with($key, ':smoke:emoji-forever'));
    expect($forever)->toBe(['infinity', 'label', 'closed_lock_with_key', 'compression']);

    $cache->flush();
})->group('live');
