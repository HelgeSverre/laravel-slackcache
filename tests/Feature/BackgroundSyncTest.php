<?php

use HelgeSverre\SlackCache\Jobs\ReloadKeyspace;
use HelgeSverre\SlackCache\Testing\FakeSlack;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->slack = FakeSlack::install();
    $this->travelTo(now()->setTimestamp(1_800_000_000));
    config()->set('cache.stores.slack.sync', 'background');
    config()->set('cache.stores.slack_other', ['sync' => 'inline', 'replica' => 'replica_other'] + config('cache.stores.slack'));
    config()->set('cache.stores.replica_other', ['driver' => 'array']);
});

it('loads a cold replica inline', function () {
    Queue::fake();
    Cache::store('slack_other')->put('k', 'v', 600);

    expect(Cache::store('slack')->get('k'))->toBe('v');
    Queue::assertNothingPushed();
});

it('serves the current replica and re-syncs in the background when it goes stale', function () {
    Queue::fake();
    Cache::store('slack')->put('k', 'v', 3600);
    Cache::store('slack_other')->put('other', 'new', 3600);
    $this->travel(31)->seconds();
    $history = $this->slack->callCount('conversations.history');

    expect(Cache::store('slack')->get('k'))->toBe('v')
        ->and(Cache::store('slack')->get('other'))->toBeNull()
        ->and($this->slack->callCount('conversations.history'))->toBe($history);

    Queue::assertPushed(ReloadKeyspace::class, 1);

    app()->call([Queue::pushed(ReloadKeyspace::class)->first(), 'handle']);

    expect(Cache::store('slack')->get('other'))->toBe('new');
});

it('queues one re-sync at a time', function () {
    Queue::fake();
    Cache::store('slack')->put('k', 'v', 3600);
    $this->travel(31)->seconds();

    foreach (range(1, 10) as $_) {
        Cache::store('slack')->get('k');
    }

    Queue::assertPushed(ReloadKeyspace::class, 1);
});

it('keeps serving the replica for a while if the queue is slow', function () {
    Queue::fake();
    Cache::store('slack')->forever('k', 'v');
    $this->travel(20)->minutes();

    expect(Cache::store('slack')->get('k'))->toBe('v');
});

it('re-syncs on the sync queue straight away', function () {
    config()->set('queue.default', 'sync');
    Cache::store('slack')->get('warm');
    Cache::store('slack_other')->put('other', 'new', 3600);
    $this->travel(31)->seconds();

    Cache::store('slack')->get('anything');

    expect(Cache::store('slack')->get('other'))->toBe('new');
});
