<?php

use HelgeSverre\SlackCache\Exceptions\SlackApiException;
use HelgeSverre\SlackCache\Exceptions\SlackCacheException;
use HelgeSverre\SlackCache\SlackStore;
use HelgeSverre\SlackCache\Testing\FakeSlack;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->slack = FakeSlack::install();
    $this->travelTo(now()->setTimestamp(1_800_000_000));

    // Another process on the same app server: same channel, same replica, its own Keyspace instance.
    config()->set('cache.stores.slack_other', config('cache.stores.slack'));
});

function replicaKeyCount(): int
{
    $store = Cache::store('replica')->getStore();

    return count((fn () => $this->storage)->call($store));
}

it('keeps writes and deletes that land while the keyspace reloads', function () {
    $cache = Cache::store('slack');
    $cache->put('overwritten', 'old', 600);
    $cache->put('deleted', 'doomed', 600);
    Cache::store('slack_other')->get('warm');

    $this->slack->beforeNext('conversations.history', function () {
        Cache::store('slack_other')->put('overwritten', 'new', 600);
        Cache::store('slack_other')->put('created', 'fresh', 600);
        Cache::store('slack_other')->forget('deleted');
    });

    $cache->getStore()->keyspace()->load();

    expect($cache->get('overwritten'))->toBe('new')
        ->and($cache->get('created'))->toBe('fresh')
        ->and($cache->get('deleted'))->toBeNull()
        ->and($this->slack->messages('slackcache'))->toHaveCount(2);
});

it('does not accumulate replica entries across re-syncs', function () {
    $cache = Cache::store('slack');

    foreach (range(1, 10) as $i) {
        $cache->forever("k{$i}", $i);
    }

    $cache->getStore()->keyspace()->load();
    $baseline = replicaKeyCount();

    foreach (range(1, 20) as $_) {
        $this->travel(31)->seconds();
        expect($cache->get('k1'))->toEqual(1);
    }

    expect(replicaKeyCount())->toBeLessThanOrEqual($baseline + 2);
});

it('does not accumulate replica entries across manual loads either', function () {
    config()->set('cache.stores.slack.sync_interval', null);
    $cache = Cache::store('slack');

    foreach (range(1, 10) as $i) {
        $cache->forever("k{$i}", $i);
    }

    $keyspace = $cache->getStore()->keyspace();
    $keyspace->load();
    $baseline = replicaKeyCount();

    foreach (range(1, 5) as $_) {
        $keyspace->load();
    }

    expect(replicaKeyCount())->toBeLessThanOrEqual($baseline + 2)
        ->and($cache->get('k10'))->toEqual(10);
});

it('acquires locks without a TTL atomically, through add()', function () {
    $store = Mockery::mock(SlackStore::class)->makePartial();
    $store->shouldReceive('add')->once()->andReturn(true);

    expect($store->lock('forever-lock')->acquire())->toBeTrue();
});

it('does not try to join a private channel given by ID', function () {
    $id = $this->slack->createPrivateChannel('secret-cache');
    config()->set('cache.stores.slack.channel', $id);

    Cache::store('slack')->put('k', 'v', 60);

    expect($this->slack->messages($id))->toHaveCount(1);
});

it('refuses to shard a channel given by ID', function () {
    config()->set('cache.stores.slack.channel', 'C0123456789');
    config()->set('cache.stores.slack.shards', 4);

    Cache::store('slack');
})->throws(SlackCacheException::class, 'channel name');

it('deletes the message before forgetting the replica entry', function () {
    Cache::store('slack')->put('k', 'v', 60);
    $this->slack->fail('internal_error');

    expect(fn () => Cache::store('slack')->forget('k'))->toThrow(SlackApiException::class);
    expect(Cache::store('slack')->get('k'))->toBe('v');
});
