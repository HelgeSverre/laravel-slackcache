<?php

use HelgeSverre\SlackCache\Testing\FakeSlack;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->slack = FakeSlack::install();
    $this->travelTo(now()->setTimestamp(1_800_000_000));
});

it('keeps locks in the cache channel by default', function () {
    Cache::store('slack')->lock('deploy', 60)->get();

    expect($this->slack->messages('slackcache'))->toHaveCount(1)
        ->and(Cache::store('slack')->getStore()->hasSeparateLockStore())->toBeFalse();
});

it('refuses to flush locks that share the cache channel', function () {
    Cache::store('slack')->getStore()->flushLocks();
})->throws(RuntimeException::class, 'lock_channel');

it('keeps locks in their own channel when lock_channel is set', function () {
    config()->set('cache.stores.slack.lock_channel', 'slackcache-locks');
    $cache = Cache::store('slack');

    $lock = $cache->lock('deploy', 60);
    expect($lock->get())->toBeTrue()
        ->and($cache->lock('deploy', 60)->get())->toBeFalse();

    $cache->put('k', 'v', 60);

    expect($this->slack->messages('slackcache-locks'))->toHaveCount(1)
        ->and($this->slack->messages('slackcache'))->toHaveCount(1)
        ->and($cache->getStore()->hasSeparateLockStore())->toBeTrue();

    $lock->release();
    expect($this->slack->messages('slackcache-locks'))->toBeEmpty();
});

it('flushes locks without touching the cache', function () {
    config()->set('cache.stores.slack.lock_channel', 'slackcache-locks');
    $cache = Cache::store('slack');
    $cache->lock('a', 60)->get();
    $cache->lock('b', 60)->get();
    $cache->put('k', 'v', 60);

    expect($cache->getStore()->flushLocks())->toBeTrue()
        ->and($this->slack->messages('slackcache-locks'))->toBeEmpty()
        ->and($cache->get('k'))->toBe('v')
        ->and($cache->lock('a', 60)->get())->toBeTrue();
});

it('restores a lock by owner from the lock channel', function () {
    config()->set('cache.stores.slack.lock_channel', 'slackcache-locks');
    $lock = Cache::store('slack')->lock('job', 60);
    $lock->get();

    expect(Cache::store('slack')->restoreLock('job', $lock->owner())->release())->toBeTrue();
});

it('clears locks with cache:clear --locks', function () {
    config()->set('cache.stores.slack.lock_channel', 'slackcache-locks');
    Cache::store('slack')->lock('a', 60)->get();

    $this->artisan('cache:clear', ['store' => 'slack', '--locks' => true])->assertSuccessful();

    expect($this->slack->messages('slackcache-locks'))->toBeEmpty();
})->skip(fn () => version_compare(app()->version(), '13.0', '<'), 'cache:clear --locks needs Laravel 13');

it('reaps expired locks in the lock channel', function () {
    config()->set('cache.stores.slack.lock_channel', 'slackcache-locks');
    Cache::store('slack')->lock('crashed-worker', 10)->get();
    $this->travel(11)->seconds();
    Cache::store('slack')->lock('crashed-worker', 10)->get();

    expect($this->slack->messages('slackcache-locks'))->toHaveCount(2);

    $this->artisan('slackcache:reap')->expectsOutputToContain('Reaped 1 expired key')->assertSuccessful();

    expect($this->slack->messages('slackcache-locks'))->toHaveCount(1);
});
