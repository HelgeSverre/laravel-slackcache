<?php

use HelgeSverre\SlackCache\Codec;
use HelgeSverre\SlackCache\KeySlot;
use HelgeSverre\SlackCache\Testing\FakeSlack;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->slack = FakeSlack::install();
    $this->travelTo(now()->setTimestamp(1_800_000_000));

    // A second application server: same channel, its own replica.
    config()->set('cache.stores.replica_b', ['driver' => 'array', 'serialize' => true]);
    config()->set('cache.stores.slack_b', ['replica' => 'replica_b'] + config('cache.stores.slack'));
});

it('rebuilds a cold replica from the channel', function () {
    Cache::store('slack')->put('survives', 'a restart', 600);

    Cache::store('replica')->flush();
    app('cache')->forgetDriver('slack');

    expect(Cache::store('slack')->get('survives'))->toBe('a restart');
});

it('serves reads from the replica without calling Slack', function () {
    Cache::store('slack')->put('k', 'v', 600);
    $before = count($this->slack->calls);

    foreach (range(1, 50) as $_) {
        Cache::store('slack')->get('k');
    }

    expect(count($this->slack->calls))->toBe($before);
});

it('picks up writes from other servers after the sync interval', function () {
    Cache::store('slack')->get('warm');
    Cache::store('slack_b')->put('shared', 'from b', 600);

    expect(Cache::store('slack')->get('shared'))->toBeNull();

    $this->travel(31)->seconds();

    expect(Cache::store('slack')->get('shared'))->toBe('from b');
});

it('lets the later Slack timestamp win when two servers write the same key', function () {
    Cache::store('slack_b')->get('warm');
    Cache::store('slack')->put('race', 'a', 600);
    Cache::store('slack_b')->put('race', 'b', 600);

    expect($this->slack->messages('slackcache'))->toHaveCount(2);

    $this->travel(31)->seconds();

    expect(Cache::store('slack')->get('race'))->toBe('b')
        ->and($this->slack->messages('slackcache'))->toHaveCount(1);
});

it('never re-syncs on its own when the sync interval is null', function () {
    config()->set('cache.stores.slack.sync_interval', null);
    Cache::store('slack')->get('warm');
    Cache::store('slack_b')->put('shared', 'from b', 86_400);

    $this->travel(1)->hour();

    expect(Cache::store('slack')->get('shared'))->toBeNull();

    $this->artisan('slackcache:load')->assertSuccessful();

    expect(Cache::store('slack')->get('shared'))->toBe('from b');
});

it('reads history across pages', function () {
    $this->slack->historyPageSize = 7;
    config()->set('cache.stores.slack.sync_interval', null);

    foreach (range(1, 30) as $i) {
        Cache::store('slack_b')->put("k{$i}", $i, 600);
    }

    expect(Cache::store('slack')->many(['k1', 'k15', 'k30']))->toEqual(['k1' => 1, 'k15' => 15, 'k30' => 30]);
});

it('spreads keys over shard channels by hash slot', function () {
    config()->set('cache.stores.slack.shards', 4);

    foreach (range(1, 40) as $i) {
        Cache::store('slack')->put("key:{$i}", $i, 600);
    }

    foreach (range(1, 4) as $shard) {
        $messages = $this->slack->messages("slackcache-{$shard}");
        expect($messages)->not->toBeEmpty();

        foreach ($messages as $message) {
            expect(KeySlot::shard(Codec::decode($message['text'])[0], 4))->toBe($shard - 1);
        }
    }

    expect(Cache::store('slack')->get('key:17'))->toEqual(17);
});

it('moves a key to its new shard on the next write after resharding', function () {
    Cache::store('slack')->put('migrating', 'v1', 600);
    expect($this->slack->messages('slackcache'))->toHaveCount(1);

    config()->set('cache.stores.slack_b', ['shards' => 2, 'channel' => 'slackcache'] + config('cache.stores.slack_b'));
    $target = 'slackcache-'.(KeySlot::shard('migrating', 2) + 1);
    Cache::store('slack_b')->put('migrating', 'v2', 600);

    expect($this->slack->messages($target))->toHaveCount(1);
});

it('works with a user token, ignoring the user\'s own chatter', function () {
    $this->slack->isBot = false;
    $this->slack->createChannel('slackcache');
    $this->slack->postAs('slackcache', 'note to self: buy milk', ['user' => $this->slack->userId]);

    Cache::store('slack')->put('k', 'v', 600);
    Cache::store('slack')->flush();

    expect($this->slack->messages('slackcache'))->toHaveCount(1)
        ->and($this->slack->latest('slackcache')['text'])->toBe('note to self: buy milk');
});

it('sends extra headers on every call', function () {
    config()->set('cache.stores.slack.headers', ['Cookie' => 'd=session']);

    Cache::store('slack')->put('k', 'v', 600);

    Http::assertSent(fn ($request) => $request->header('Cookie') === ['d=session']);
});

it('uses a per-process replica when none is configured', function () {
    config()->set('cache.stores.slack.replica', null);

    Cache::store('slack')->put('k', 'v', 600);
    app('cache')->forgetDriver('slack');

    expect(Cache::store('slack')->get('k'))->toBe('v');
});
