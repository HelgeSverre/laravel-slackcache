<?php

use HelgeSverre\SlackCache\Codec;
use HelgeSverre\SlackCache\Testing\FakeSlack;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->slack = FakeSlack::install();
    $this->travelTo(now()->setTimestamp(1_800_000_000));
});

/** @return list<string> */
function slackKeys(FakeSlack $slack, string $channel = 'slackcache'): array
{
    return array_values(array_map(fn (array $m) => Codec::decode($m['text'])[0] ?? '', $slack->messages($channel)));
}

it('serves stale values while refreshing in the background with flexible()', function () {
    $cache = Cache::store('slack');
    $calls = 0;
    $compute = function () use (&$calls) {
        return 'v'.++$calls;
    };

    expect($cache->flexible('report', [10, 60], $compute))->toBe('v1');

    $this->travel(20)->seconds(); // Stale, not expired.

    expect($cache->flexible('report', [10, 60], $compute))->toBe('v1');

    app(DeferredCallbackCollection::class)->invoke();

    expect($cache->get('report'))->toBe('v2')
        ->and($calls)->toBe(2);
});

it('memoizes reads for the rest of the request with Cache::memo()', function () {
    Cache::store('slack')->put('k', 'v', 600);
    $before = $this->slack->callCount();

    expect(Cache::memo('slack')->get('k'))->toBe('v');

    // Another process changes the key. This request keeps its memoized copy.
    config()->set('cache.stores.slack_other', config('cache.stores.slack'));
    Cache::store('slack_other')->put('k', 'changed', 600);

    expect(Cache::memo('slack')->get('k'))->toBe('v')
        ->and(Cache::store('slack')->get('k'))->toBe('changed');
});

it('stores sessions in Slack', function () {
    config()->set('session.driver', 'slack');
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));

    Route::middleware('web')->get('/login', function () {
        session(['user_id' => 42]);

        return 'ok';
    });
    Route::middleware('web')->get('/me', fn () => (string) session('user_id'));

    $response = $this->get('/login')->assertOk();
    $cookie = $response->getCookie(config('session.cookie'), false);

    expect(slackKeys($this->slack))->toHaveCount(1);

    $this->withCookie(config('session.cookie'), $cookie->getValue())
        ->get('/me')
        ->assertSee('42');
});

it('rate limits with a rate-limited API', function () {
    config()->set('cache.limiter', 'slack');

    $attempts = collect(range(1, 4))->map(fn () => RateLimiter::attempt('send-invoice', 3, fn () => true, 60));

    expect($attempts->all())->toBe([true, true, true, false])
        ->and(RateLimiter::tooManyAttempts('send-invoice', 3))->toBeTrue();
});

it('works with cache:forget and cache:clear', function () {
    Cache::store('slack')->put('a', 1, 600);
    Cache::store('slack')->put('b', 2, 600);

    $this->artisan('cache:forget', ['key' => 'a', 'store' => 'slack'])->assertSuccessful();
    expect(slackKeys($this->slack))->toBe(['b']);

    $this->artisan('cache:clear', ['store' => 'slack'])->assertSuccessful();
    expect($this->slack->messages('slackcache'))->toBeEmpty();
});

it('fails over to another store when Slack is down', function () {
    config()->set('cache.stores.failover', ['driver' => 'failover', 'stores' => ['slack', 'array']]);
    $this->slack->fail('service_unavailable', times: 100);

    Cache::store('failover')->put('k', 'v', 600);

    expect(Cache::store('failover')->get('k'))->toBe('v')
        ->and(Cache::store('array')->get('k'))->toBe('v');
});

it('adds a section to php artisan about', function () {
    config()->set('cache.default', 'slack');

    $this->artisan('about', ['--only' => 'slackcache'])
        ->expectsOutputToContain('SlackCache')
        ->expectsOutputToContain('#slackcache · replica: replica · allkeys-slack · default')
        ->assertSuccessful();

    expect($this->slack->callCount())->toBe(0);
});
