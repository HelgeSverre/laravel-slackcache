<?php

use HelgeSverre\SlackCache\Exceptions\SlackCacheException;
use HelgeSverre\SlackCache\Testing\FakeSlack;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->slack = FakeSlack::install();
    $this->travelTo(now()->setTimestamp(1_800_000_000));
});

it('loads the keyspace from Slack', function () {
    Cache::store('slack')->put('a', 1, 600);
    Cache::store('slack')->put('b', 2, 600);

    $this->artisan('slackcache:load')
        ->expectsOutputToContain('Loaded 2 keys from Slack')
        ->assertSuccessful();
});

it('reaps expired keys, 20 at a time', function () {
    foreach (range(1, 25) as $i) {
        Cache::store('slack')->put("short:{$i}", $i, 10);
    }
    Cache::store('slack')->put('long', 'lived', 600);

    $this->travel(11)->seconds();

    $this->artisan('slackcache:reap')->expectsOutputToContain('Reaped 20 expired keys')->assertSuccessful();
    $this->artisan('slackcache:reap')->expectsOutputToContain('Reaped 5 expired keys')->assertSuccessful();
    $this->artisan('slackcache:reap')->expectsOutputToContain('Reaped 0 expired keys')->assertSuccessful();

    expect($this->slack->messages('slackcache'))->toHaveCount(1)
        ->and(Cache::store('slack')->get('long'))->toBe('lived');
});

it('reaps keys the free plan has evicted', function () {
    Cache::store('slack')->forever('old', 'news');
    $this->travel(91)->days();

    $this->artisan('slackcache:reap', ['--limit' => 5])->expectsOutputToContain('Reaped 1 expired key.')->assertSuccessful();
});

it('shows store info', function () {
    $this->artisan('slackcache:info')
        ->expectsOutputToContain('#slackcache')
        ->expectsOutputToContain('allkeys-slack')
        ->assertSuccessful();
});

it('prints the Slack app manifest', function () {
    $this->artisan('slackcache:manifest')
        ->expectsOutputToContain('channels:history')
        ->expectsOutputToContain('Eviction policy is the free plan')
        ->assertSuccessful();
});

it('complains about stores that do not use the slack driver', function () {
    $this->artisan('slackcache:info', ['--store' => 'replica']);
})->throws(SlackCacheException::class, 'does not use the slack driver');
