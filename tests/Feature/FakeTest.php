<?php

use HelgeSverre\SlackCache\SlackCache;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\AssertionFailedError;

it('fakes Slack for every slack store and asserts what was stored', function () {
    config()->set('cache.prefix', 'app_');
    config()->set('cache.stores.slack.token', null);
    config()->set('cache.stores.slack.replica', 'file');
    config()->set('cache.stores.slack.base_url', 'https://example.slack.com/api/');

    $slack = SlackCache::fake();

    Cache::store('slack')->put('greeting', 'hello', 60);
    Cache::store('slack')->forever('settings', ['theme' => 'dark']);
    Cache::store('slack')->put('visits', 7, 60);

    $slack->assertStored('greeting')
        ->assertStored('greeting', 'hello')
        ->assertStored('settings', ['theme' => 'dark'])
        ->assertStored('visits', fn ($value) => $value == 7)
        ->assertNotStored('missing')
        ->assertStoredCount(3);

    expect($slack->stored())->toBe(['greeting' => 'hello', 'settings' => ['theme' => 'dark'], 'visits' => '7'])
        ->and(config('cache.stores.slack.replica'))->toBe('array');

    Cache::store('slack')->forget('greeting');
    $slack->assertNotStored('greeting');
});

it('fails assertions with a readable message', function () {
    $slack = SlackCache::fake();
    Cache::store('slack')->put('greeting', 'hello', 60);

    expect(fn () => $slack->assertStored('nope'))->toThrow(AssertionFailedError::class, 'Key [nope] is not stored in Slack.')
        ->and(fn () => $slack->assertStored('greeting', 'goodbye'))->toThrow(AssertionFailedError::class, 'different value')
        ->and(fn () => $slack->assertStored('greeting', fn () => false))->toThrow(AssertionFailedError::class, 'fails the callback')
        ->and(fn () => $slack->assertNotStored('greeting'))->toThrow(AssertionFailedError::class, 'Key [greeting] is stored in Slack.')
        ->and(fn () => $slack->assertNothingStored())->toThrow(AssertionFailedError::class, 'Expected 0 keys in Slack, found 1.');
});

it('does not count expired keys, or messages from people', function () {
    $this->travelTo(now()->setTimestamp(1_800_000_000));
    $slack = SlackCache::fake();
    Cache::store('slack')->put('brief', 'v', 10);
    $slack->postAs('slackcache', 'is this channel important?');

    $slack->assertStored('brief');
    $this->travel(11)->seconds();
    $slack->assertNothingStored();
});

it('reads encrypted and compressed values back for the assertions', function () {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    config()->set('cache.stores.slack.encrypt', true);
    config()->set('cache.stores.slack.compress', true);
    $slack = SlackCache::fake();

    Cache::store('slack')->put('secret', str_repeat('classified ', 100), 60);

    $slack->assertStored('secret', str_repeat('classified ', 100));
    expect($slack->latest('slackcache')['text'])->not->toContain(base64_encode('classified'));
});

it('isolates tests: a fresh fake starts empty', function () {
    $first = SlackCache::fake();
    Cache::store('slack')->put('leftover', 'v', 60);
    $first->assertStoredCount(1);

    $second = SlackCache::fake();
    $second->assertNothingStored();
    expect(Cache::store('slack')->get('leftover'))->toBeNull();
});

it('passes options through to the fake workspace', function () {
    $slack = SlackCache::fake(isBot: false);
    Cache::store('slack')->put('k', 'v', 60);

    expect($slack->latest('slackcache'))->not->toHaveKey('bot_id');
});
