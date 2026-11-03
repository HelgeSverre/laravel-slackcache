<?php

use HelgeSverre\SlackCache\Events\SlackApiCalled;
use HelgeSverre\SlackCache\Pulse\SlackCacheCard;
use HelgeSverre\SlackCache\Testing\FakeSlack;
use HelgeSverre\SlackCache\Tests\PulseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Laravel\Pulse\Facades\Pulse;
use Livewire\Livewire;

uses(PulseTestCase::class);

beforeEach(function () {
    Sleep::fake();
    $this->slack = FakeSlack::install();
});

it('reports every Slack API call with its duration and outcome', function () {
    Event::fake([SlackApiCalled::class]);
    $this->slack->fail('ratelimited_by_vibes');

    expect(fn () => Cache::store('slack')->put('k', 'v', 60))->toThrow(Exception::class);
    Cache::store('slack')->put('k', 'v', 60);

    Event::assertDispatched(SlackApiCalled::class, fn (SlackApiCalled $e) => $e->method === 'auth.test' && $e->error === 'ratelimited_by_vibes');
    Event::assertDispatched(SlackApiCalled::class, fn (SlackApiCalled $e) => $e->method === 'chat.postMessage' && $e->error === null && $e->milliseconds >= 0);
});

it('records calls, rate limits, retries, errors and reloads for Pulse', function () {
    $cache = Cache::store('slack');
    $cache->put('k', 'v', 60);
    $this->slack->rateLimit(1, 3)->serverError(1);
    $cache->put('k', 'v2', 60);
    $this->slack->fail('channel_not_found');
    expect(fn () => $cache->put('k', 'v3', 60))->toThrow(Exception::class);
    Pulse::ingest();

    $rows = DB::table('pulse_aggregates')->where('aggregate', 'count')->where('period', 60)->get()
        ->mapWithKeys(fn ($row) => [$row->type.' '.$row->key => (int) $row->value])->all();

    expect($rows)->toMatchArray([
        'slackcache_call auth.test' => 1,
        'slackcache_call chat.postMessage' => 3,
        'slackcache_rate_limited chat.postMessage' => 1,
        'slackcache_retry chat.postMessage' => 1,
        'slackcache_reload slackcache' => 1,
        'slackcache_error chat.postMessage: channel_not_found' => 1,
    ])->and($rows)->toHaveKey('slackcache_call chat.delete');
});

it('renders the card', function () {
    $cache = Cache::store('slack');
    $cache->put('k', 'v', 60);
    $this->slack->rateLimit(1, 3);
    $cache->put('k', 'v2', 60);
    Pulse::ingest();

    Livewire::withoutLazyLoading()->test(SlackCacheCard::class)
        ->assertSee('SlackCache')
        ->assertSee('chat.postMessage')
        ->assertSee('Rate limited (3s waited)')
        ->assertSee('Reloads');
});

it('registers the card as <livewire:slackcache />', function () {
    expect(app('livewire')->new('slackcache'))->toBeInstanceOf(SlackCacheCard::class);
});
