<?php

use HelgeSverre\SlackCache\Health\SlackCacheCheck;
use HelgeSverre\SlackCache\Testing\FakeSlack;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Spatie\Health\Enums\Status;

beforeEach(function () {
    Sleep::fake();
    $this->slack = FakeSlack::install();
});

it('passes when the token works and the channels resolve', function () {
    $result = SlackCacheCheck::new()->run();

    expect($result->status)->toBe(Status::ok())
        ->and($result->getShortSummary())->toBe('#slackcache')
        ->and($result->meta)->toMatchArray([
            'workspace' => 'Fake Workspace',
            'token' => 'bot',
            'channels' => '#slackcache',
            'pending_writes' => 0,
        ]);
});

it('fails when Slack rejects the token', function () {
    $this->slack->fail('invalid_auth');

    $result = SlackCacheCheck::new()->run();

    expect($result->status)->toBe(Status::failed())
        ->and($result->notificationMessage)->toContain('invalid_auth')
        ->and($result->getShortSummary())->toBe('Unreachable');
});

it('fails for a store that does not use the slack driver', function () {
    $result = SlackCacheCheck::new()->store('replica')->run();

    expect($result->status)->toBe(Status::failed())
        ->and($result->notificationMessage)->toContain('[replica] does not use the slack driver');
});

it('fails for a store that cannot be built', function () {
    config()->set('cache.stores.slack.token', null);

    expect(SlackCacheCheck::new()->run()->status)->toBe(Status::failed());
});

it('warns, then fails, as queued writes pile up', function () {
    Queue::fake();
    config()->set('cache.stores.slack.async', true);

    foreach (range(1, 4) as $i) {
        Cache::store('slack')->put("k{$i}", 'v', 60);
    }

    $check = fn () => SlackCacheCheck::new()->warnWhenPendingWritesAbove(2)->failWhenPendingWritesAbove(3)->run();

    expect($check()->status)->toBe(Status::failed())
        ->and($check()->notificationMessage)->toContain('4 writes are queued for Slack. Is a queue worker running?')
        ->and($check()->getShortSummary())->toBe('#slackcache · 4 pending');

    Cache::store('replica')->flush(); // The queue drained, as far as the replica knows.

    expect($check()->status)->toBe(Status::ok());
});

it('warns when Slack is slow', function () {
    $result = SlackCacheCheck::new()->warnWhenSlowerThan(-1)->run();

    expect($result->status)->toBe(Status::warning())
        ->and($result->notificationMessage)->toContain('Slack took');
});
