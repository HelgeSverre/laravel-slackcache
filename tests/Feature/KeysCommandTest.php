<?php

use HelgeSverre\SlackCache\Testing\FakeSlack;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->slack = FakeSlack::install();
    $this->travelTo(now()->setTimestamp(1_800_000_000));
    config()->set('cache.stores.slack.prefix', 'app:');
});

it('lists live keys matching a glob pattern, without the store prefix', function () {
    $cache = Cache::store('slack');
    $cache->put('user:1', 'a', 600);
    $cache->put('user:2', 'b', 600);
    $cache->put('order:1', 'c', 600);
    $cache->put('user:expired', 'd', 10);
    $this->travel(11)->seconds();

    $this->artisan('slackcache:keys', ['pattern' => 'user:*'])
        ->expectsOutput('user:1')
        ->expectsOutput('user:2')
        ->doesntExpectOutput('order:1')
        ->doesntExpectOutput('user:expired')
        ->assertSuccessful();
});

it('lists every key by default', function () {
    Cache::store('slack')->put('a', 1, 600);
    Cache::store('slack')->put('b', 2, 600);

    $this->artisan('slackcache:keys')
        ->expectsOutputToContain('Redis advises against KEYS in production')
        ->expectsOutput('a')
        ->expectsOutput('b')
        ->expectsOutputToContain('2 keys')
        ->assertSuccessful();
});

it('supports ? and character classes like Redis KEYS', function () {
    foreach (['hello', 'hallo', 'hxllo', 'heello'] as $key) {
        Cache::store('slack')->put($key, 1, 600);
    }

    $this->artisan('slackcache:keys', ['pattern' => 'h[ae]llo'])
        ->expectsOutput('hallo')
        ->expectsOutput('hello')
        ->doesntExpectOutput('hxllo')
        ->doesntExpectOutput('heello')
        ->assertSuccessful();

    $this->artisan('slackcache:keys', ['pattern' => 'h?llo'])
        ->expectsOutput('hxllo')
        ->doesntExpectOutput('heello')
        ->assertSuccessful();
});

it('skips keys from other prefixes and other clients', function () {
    Cache::store('slack')->put('mine', 1, 600);
    $this->slack->postAs('slackcache', 'slackcache:v1 aGVsbG8= - d29ybGQ=', ['bot_id' => $this->slack->botId]);

    $this->artisan('slackcache:keys')
        ->expectsOutput('mine')
        ->doesntExpectOutput('hello')
        ->assertSuccessful();
});
