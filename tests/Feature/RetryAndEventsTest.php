<?php

use HelgeSverre\SlackCache\Events\HistoryReplyFailed;
use HelgeSverre\SlackCache\Events\KeyspaceReloaded;
use HelgeSverre\SlackCache\Events\SlackApiCalled;
use HelgeSverre\SlackCache\Events\SlackRateLimited;
use HelgeSverre\SlackCache\Events\SlackRequestRetrying;
use HelgeSverre\SlackCache\Exceptions\SlackApiException;
use HelgeSverre\SlackCache\Slack\SlackApi;
use HelgeSverre\SlackCache\Testing\FakeSlack;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;

beforeEach(function () {
    $this->slack = FakeSlack::install();
    $this->travelTo(now()->setTimestamp(1_800_000_000));
    Sleep::fake();
});

it('retries Slack server errors with exponential backoff', function () {
    Cache::store('slack')->put('warm', 1, 600);
    $this->slack->serverError(times: 3, status: 503);

    Cache::store('slack')->put('k', 'v', 600);

    Sleep::assertSequence([
        Sleep::for(1)->seconds(),
        Sleep::for(2)->seconds(),
        Sleep::for(4)->seconds(),
    ]);
    expect(Cache::store('slack')->get('k'))->toBe('v');
});

it('retries dropped connections', function () {
    Cache::store('slack')->put('warm', 1, 600);
    $this->slack->disconnect(times: 2);

    Cache::store('slack')->put('k', 'v', 600);

    Sleep::assertSleptTimes(2);
    expect(Cache::store('slack')->get('k'))->toBe('v');
});

it('gives up on server errors after the configured retries', function () {
    config()->set('cache.stores.slack.retries', 2);
    Cache::store('slack')->put('warm', 1, 600);
    $this->slack->serverError(times: 5);

    Cache::store('slack')->put('k', 'v', 600);
})->throws(SlackApiException::class, 'chat.postMessage failed: http_500');

it('caps the backoff at 30 seconds', function () {
    config()->set('cache.stores.slack.retries', 7);
    Cache::store('slack')->put('warm', 1, 600);
    $this->slack->serverError(times: 7);

    Cache::store('slack')->put('k', 'v', 600);

    Sleep::assertSequence(array_map(fn (int $s) => Sleep::for($s)->seconds(), [1, 2, 4, 8, 16, 30, 30]));
});

it('dispatches events for rate limits and retries', function () {
    Event::fake([SlackRateLimited::class, SlackRequestRetrying::class]);
    Cache::store('slack')->put('warm', 1, 600);

    $this->slack->rateLimit(times: 1, retryAfter: 7);
    Cache::store('slack')->put('a', 1, 600);
    $this->slack->serverError(times: 1, status: 502);
    Cache::store('slack')->put('b', 1, 600);

    Event::assertDispatched(SlackRateLimited::class, fn (SlackRateLimited $e) => $e->method === 'chat.postMessage' && $e->retryAfter === 7 && $e->attempt === 1);
    Event::assertDispatched(SlackRequestRetrying::class, fn (SlackRequestRetrying $e) => $e->method === 'chat.postMessage' && $e->reason === 'http_502' && $e->delay === 1);
});

it('dispatches an event when the keyspace reloads', function () {
    Cache::store('slack')->put('a', 1, 600);
    Cache::store('slack')->put('b', 2, 600);
    Event::fake([KeyspaceReloaded::class]);

    Cache::store('slack')->getStore()->keyspace()->load();

    Event::assertDispatched(KeyspaceReloaded::class, fn (KeyspaceReloaded $e) => $e->keys === 2 && $e->channels === ['slackcache'] && $e->milliseconds >= 0);
});

it('dispatches an event when a history reply fails', function () {
    Event::fake([HistoryReplyFailed::class]);
    config()->set('cache.stores.slack.history', true);
    Cache::store('slack')->put('k', 'one', 600);
    $this->slack->beforeNext('chat.postMessage', fn () => $this->slack->fail('internal_error'));

    Cache::store('slack')->put('k', 'two', 600);

    Event::assertDispatched(HistoryReplyFailed::class, fn (HistoryReplyFailed $e) => $e->key === 'k' && $e->exception->getMessage() === 'Slack API call chat.postMessage failed: internal_error');
});

it('makes exactly retries + 1 attempts, for every kind of failure', function (Closure $fail, string $error) {
    config()->set('cache.stores.slack.retries', 2);
    $cache = Cache::store('slack');
    $cache->put('warm', 1, 600);
    $before = $this->slack->callCount('chat.postMessage');

    // Two failures: the third attempt succeeds.
    $fail($this->slack, 2);
    $cache->put('k', 'v', 600);
    expect($this->slack->callCount('chat.postMessage') - $before)->toBe(3);

    // Three failures: the third attempt is the last.
    $fail($this->slack, 5);
    expect(fn () => $cache->put('k', 'v2', 600))->toThrow(SlackApiException::class, $error);
    expect($this->slack->callCount('chat.postMessage') - $before)->toBe(6);
})->with([
    'server error' => [fn (FakeSlack $slack, int $n) => $slack->serverError($n), 'http_500'],
    'rate limit' => [fn (FakeSlack $slack, int $n) => $slack->rateLimit($n), 'http_429'],
    'dropped connection' => [fn (FakeSlack $slack, int $n) => $slack->disconnect($n), 'connection_failed'],
]);

it('makes exactly retries + 1 attempts at a file upload', function () {
    config()->set('cache.stores.slack.retries', 2);
    config()->set('cache.stores.slack.files', true);
    $cache = Cache::store('slack');
    $cache->put('warm', 1, 600);

    $this->slack->fileOutage(2, 503);
    $cache->put('big', random_bytes(20_000), 600);
    expect($this->slack->callCount('files.slack.com/upload/v1/'.array_key_first($this->slack->files)))->toBe(3);

    $this->slack->fileOutage(5, 503);
    expect(fn () => $cache->put('big2', random_bytes(20_000), 600))->toThrow(SlackApiException::class, 'files.upload failed: http_503');
});

it('reports how long each call took', function () {
    $events = [];
    Event::listen(SlackApiCalled::class, function ($event) use (&$events) {
        $events[] = $event;
    });

    Cache::store('slack')->put('k', 'v', 600);

    expect($events)->not->toBeEmpty();
    foreach ($events as $event) {
        expect($event->milliseconds)->toBeGreaterThanOrEqual(0)->toBeLessThan(5_000);
    }
});

it('tells token types apart by prefix', function (string $token, string $type) {
    expect((new SlackApi(app(Factory::class), $token))->tokenType())->toBe($type);
})->with([
    ['xoxb-1', 'bot'],
    ['xoxp-1', 'user'],
    ['xoxc-1', 'browser session'],
    ['xoxe.xoxp-1', 'configuration'],
    ['nope-xoxb-', 'unknown'],
]);

it('reads the scopes Slack reports, trimmed', function () {
    $this->slack->scopes = ['chat:write', ' channels:read', 'channels:history '];
    $api = Cache::store('slack')->getStore()->keyspace()->api();
    $api->call('auth.test');

    expect($api->scopes())->toBe(['chat:write', 'channels:read', 'channels:history']);
});
