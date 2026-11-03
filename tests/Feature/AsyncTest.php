<?php

use HelgeSverre\SlackCache\Codec;
use HelgeSverre\SlackCache\Exceptions\SlackApiException;
use HelgeSverre\SlackCache\Exceptions\SlackCacheException;
use HelgeSverre\SlackCache\Jobs\DeleteFromSlack;
use HelgeSverre\SlackCache\Jobs\WriteToSlack;
use HelgeSverre\SlackCache\Testing\FakeSlack;
use Illuminate\Contracts\Bus\Dispatcher;
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
    $cache->increment('hits'); // Holds the key's lock while the job runs inline.
    $cache->add('once', 'yes', 600);
    $cache->forget('hits');

    expect(slackValues($this->slack))->toEqualCanonicalizing([serialize('two'), serialize('yes')])
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

it('keeps a pending write in the replica while the queue is slow', function () {
    Queue::fake();
    Cache::store('slack')->put('k', 'v', 86_400);

    $this->travel(30)->minutes();

    expect(Cache::store('slack')->get('k'))->toBe('v');

    runSlackJobs();

    expect(slackValues($this->slack))->toBe([serialize('v')]);
});

it('still deletes the message when a delete job runs very late', function () {
    Queue::fake();
    $cache = Cache::store('slack');
    $cache->forever('k', 'v');
    runSlackJobs([0]);
    $cache->forget('k');

    $this->travel(2)->days(); // The replica's tombstone is long gone.

    runSlackJobs([1]);

    expect($this->slack->messages('slackcache'))->toBeEmpty();
});

it('refuses write-behind without a shared replica', function () {
    config()->set('cache.stores.slack.replica', null);

    Cache::store('slack');
})->throws(SlackCacheException::class, 'replica');

it('does not let a late retry of an older write revert a newer one after a reload', function () {
    Queue::fake();
    $cache = Cache::store('slack');
    $cache->put('k', 'one', 3600);
    $cache->put('k', 'two', 3600);

    runSlackJobs([1]); // 'two' reaches Slack; 'one' is still waiting for a retry
    $cache->getStore()->keyspace()->load();
    runSlackJobs([0]); // the late retry of 'one'

    expect(slackValues($this->slack))->toBe([serialize('two')])
        ->and($cache->get('k'))->toBe('two');
});

it('keeps a write that lands while an older job is talking to Slack', function () {
    Queue::fake();
    $cache = Cache::store('slack');
    $cache->put('k', 'one', 3600);

    // While job 'one' posts to Slack, the app writes 'two'.
    $this->slack->beforeNext('chat.postMessage', fn () => $cache->put('k', 'two', 3600));
    runSlackJobs([0]);

    expect($cache->get('k'))->toBe('two');

    runSlackJobs([1]);

    expect(slackValues($this->slack))->toBe([serialize('two')])
        ->and($cache->get('k'))->toBe('two');
});

it('deletes the message of a write that lost the race to a forget', function () {
    Queue::fake();
    $cache = Cache::store('slack');
    $cache->put('k', 'one', 3600);

    $this->slack->beforeNext('chat.postMessage', fn () => $cache->forget('k'));
    runSlackJobs([0]);
    runSlackJobs([1]);

    expect($this->slack->messages('slackcache'))->toBeEmpty()
        ->and($cache->get('k'))->toBeNull();
});

it('does not double-post when a write job is retried after posting', function () {
    Queue::fake();
    $cache = Cache::store('slack');
    $cache->put('k', 'one', 3600);
    runSlackJobs([0]);
    $cache->put('k', 'two', 3600);

    $this->slack->beforeNext('chat.delete', fn () => $this->slack->fail('internal_error'));

    expect(fn () => runSlackJobs([1]))->toThrow(SlackApiException::class);

    runSlackJobs([1]); // the queue retries

    expect(slackValues($this->slack))->toBe([serialize('two')]);
});

it('serves reads when the re-sync job cannot be queued', function () {
    config()->set('cache.stores.slack.async', false);
    config()->set('cache.stores.slack.sync', 'background');
    Cache::store('slack')->put('k', 'v', 3600);
    $this->travel(31)->seconds();

    $this->mock(Dispatcher::class)
        ->shouldReceive('dispatch')
        ->andThrow(new RuntimeException('queue down'));

    expect(Cache::store('slack')->get('k'))->toBe('v');
});

it('deletes the message of a write whose key was deleted and cleaned up while it posted', function () {
    Queue::fake();
    $cache = Cache::store('slack');
    $cache->put('k', 'one', 3600);

    // While job 'one' posts, the app forgets the key and the delete job runs to completion.
    $this->slack->beforeNext('chat.postMessage', function () use ($cache) {
        $cache->forget('k');
        app()->call([Queue::pushed(DeleteFromSlack::class)->first(), 'handle']);
    });
    app()->call([Queue::pushed(WriteToSlack::class)->first(), 'handle']);

    expect($this->slack->messages('slackcache'))->toBeEmpty()
        ->and($cache->get('k'))->toBeNull();
});
