<?php

use HelgeSverre\SlackCache\Codec;
use HelgeSverre\SlackCache\Exceptions\SlackCacheException;
use HelgeSverre\SlackCache\Jobs\DeleteFromSlack;
use HelgeSverre\SlackCache\Jobs\WriteToSlack;
use HelgeSverre\SlackCache\Testing\FakeSlack;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->slack = FakeSlack::install();
    $this->travelTo(now()->setTimestamp(1_800_000_000));
    config()->set('cache.stores.slack.async', true);
});

/**
 * Run the queued SlackCache jobs, in the given order (default: as pushed).
 *
 * @param  list<int>|null  $order
 */
function runSlackJobs(?array $order = null): void
{
    $jobs = collect([WriteToSlack::class, DeleteFromSlack::class])
        ->flatMap(fn ($class) => Queue::pushed($class))
        ->sortBy(fn ($job) => $job->seq)
        ->values();

    foreach ($order ?? array_keys($jobs->all()) as $index) {
        app()->call([$jobs[$index], 'handle']);
    }
}

function slackValues(FakeSlack $slack): array
{
    return array_values(array_map(fn ($m) => Codec::decode($m['text'])[1], $slack->messages('slackcache')));
}

it('writes to the replica at once and to Slack from a queued job', function () {
    Queue::fake();
    $cache = Cache::store('slack');
    $cache->get('warm');
    $posts = $this->slack->callCount('chat.postMessage');

    $cache->put('k', 'v', 600);

    expect($cache->get('k'))->toBe('v')
        ->and($this->slack->callCount('chat.postMessage'))->toBe($posts);
    Queue::assertPushed(WriteToSlack::class, 1);

    runSlackJobs();

    expect(slackValues($this->slack))->toBe([serialize('v')]);
});

it('keeps the newest value when jobs run in order', function () {
    Queue::fake();
    $cache = Cache::store('slack');
    $cache->put('k', 'one', 600);
    $cache->put('k', 'two', 600);
    $cache->put('k', 'three', 600);

    expect($cache->get('k'))->toBe('three');

    runSlackJobs();

    expect(slackValues($this->slack))->toBe([serialize('three')])
        ->and($cache->get('k'))->toBe('three');
});

it('keeps the newest value when jobs run out of order', function () {
    Queue::fake();
    $cache = Cache::store('slack');
    $cache->put('k', 'one', 600);
    $cache->put('k', 'two', 600);

    runSlackJobs([1, 0]); // 'two' first, then the stale 'one'

    expect(slackValues($this->slack))->toBe([serialize('two')])
        ->and($cache->get('k'))->toBe('two');
});

it('reads its own write while an earlier job is still running', function () {
    Queue::fake();
    $cache = Cache::store('slack');
    $cache->put('k', 'one', 600);
    $cache->put('k', 'two', 600);

    runSlackJobs([0]); // only 'one' has reached Slack

    expect($cache->get('k'))->toBe('two');

    runSlackJobs([1]);

    expect(slackValues($this->slack))->toBe([serialize('two')]);
});

it('forgets through a queued job too', function () {
    Queue::fake();
    $cache = Cache::store('slack');
    $cache->put('k', 'v', 600);
    runSlackJobs([0]);

    expect($cache->forget('k'))->toBeTrue()
        ->and($cache->get('k'))->toBeNull()
        ->and(slackValues($this->slack))->toHaveCount(1);

    runSlackJobs([1]);

    expect($this->slack->messages('slackcache'))->toBeEmpty();
});

it('does not resurrect a key forgotten before its write reached Slack', function () {
    Queue::fake();
    $cache = Cache::store('slack');
    $cache->put('k', 'v', 600);
    $cache->forget('k');

    runSlackJobs();

    expect($this->slack->messages('slackcache'))->toBeEmpty()
        ->and($cache->get('k'))->toBeNull();
});

it('does not resurrect keys written before a flush', function () {
    Queue::fake();
    $cache = Cache::store('slack');
    $cache->put('k', 'v', 600);
    $cache->flush();

    runSlackJobs();

    expect($this->slack->messages('slackcache'))->toBeEmpty()
        ->and($cache->get('k'))->toBeNull();
});

it('increments locally and atomically, then writes the result', function () {
    Queue::fake();
    $cache = Cache::store('slack');

    expect($cache->increment('hits'))->toBe(1)
        ->and($cache->increment('hits'))->toBe(2)
        ->and($cache->increment('hits'))->toBe(3);

    runSlackJobs();

    expect(slackValues($this->slack))->toBe(['3']);
});

it('runs jobs on the configured connection and queue', function () {
    Queue::fake();
    config()->set('cache.stores.slack.queue_connection', 'redis');
    config()->set('cache.stores.slack.queue', 'slack-writes');

    Cache::store('slack')->put('k', 'v', 600);

    Queue::assertPushedOn('slack-writes', WriteToSlack::class);
    Queue::assertPushed(WriteToSlack::class, fn ($job) => $job->connection === 'redis');
});

it('behaves like a synchronous store on the sync queue', function () {
    config()->set('queue.default', 'sync');
    $cache = Cache::store('slack');

    $cache->put('k', 'one', 600);
    $cache->put('k', 'two', 600);
    $cache->forget('gone');

    expect(slackValues($this->slack))->toBe([serialize('two')])
        ->and($cache->get('k'))->toBe('two');
});

it('carries binary values through the queue', function () {
    Queue::fake();
    config()->set('cache.stores.slack.compress', true);
    $value = str_repeat('compressible ', 500);

    Cache::store('slack')->put('k', $value, 600);
    $job = Queue::pushed(WriteToSlack::class)->first();

    expect(json_encode(serialize($job)))->not->toBeFalse();

    runSlackJobs();
    Cache::store('replica')->flush();
    app('cache')->forgetDriver('slack');

    expect(Cache::store('slack')->get('k'))->toBe($value);
});

it('refuses to combine async with history', function () {
    config()->set('cache.stores.slack.history', true);

    Cache::store('slack');
})->throws(SlackCacheException::class, 'history');
