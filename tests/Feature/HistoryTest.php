<?php

use HelgeSverre\SlackCache\Codec;
use HelgeSverre\SlackCache\Exceptions\SlackCacheException;
use HelgeSverre\SlackCache\Testing\FakeSlack;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->slack = FakeSlack::install();
    $this->travelTo(now()->setTimestamp(1_800_000_000));
    config()->set('cache.stores.slack.history', true);
});

it('edits the key\'s message in place and keeps previous values in its thread', function () {
    $cache = Cache::store('slack');

    $cache->forever('config', ['theme' => 'light']);
    $ts = array_key_first($this->slack->messages('slackcache'));

    $cache->forever('config', ['theme' => 'dark']);
    $cache->forever('config', ['theme' => 'solarized']);

    $messages = $this->slack->messages('slackcache');
    $replies = array_values($this->slack->replies('slackcache', $ts));

    expect($messages)->toHaveCount(1)
        ->and(array_key_first($messages))->toBe($ts)
        ->and($cache->get('config'))->toBe(['theme' => 'solarized'])
        ->and($replies)->toHaveCount(2)
        ->and($replies[0]['text'])->toContain('"theme": "light"')
        ->and($replies[1]['text'])->toContain('"theme": "dark"')
        ->and(Codec::decode($replies[0]['text']))->toBeNull();
});

it('survives a cold replica: the edited message holds the latest value', function () {
    Cache::store('slack')->put('k', 'one', 600);
    Cache::store('slack')->put('k', 'two', 600);

    Cache::store('replica')->flush();
    app('cache')->forgetDriver('slack');

    expect(Cache::store('slack')->get('k'))->toBe('two')
        ->and($this->slack->messages('slackcache'))->toHaveCount(1);
});

it('records counters and TTL changes in the history too', function () {
    $cache = Cache::store('slack');
    $cache->put('hits', 1, 600);
    $ts = array_key_first($this->slack->messages('slackcache'));

    $cache->increment('hits');
    $cache->getStore()->touch('hits', 1200);

    expect($this->slack->replies('slackcache', $ts))->toHaveCount(2)
        ->and($cache->get('hits'))->toEqual(2);
});

it('does not keep history unless asked', function () {
    config()->set('cache.stores.slack.history', false);
    Cache::store('slack')->put('k', 'one', 600);
    Cache::store('slack')->put('k', 'two', 600);

    $ts = array_key_first($this->slack->messages('slackcache'));

    expect($this->slack->replies('slackcache', $ts))->toBeEmpty()
        ->and($this->slack->callCount('chat.update'))->toBe(0);
});

it('restores the previous value and throws when Slack edits the text differently', function () {
    Cache::store('slack')->put('k', 'short', 600);
    $this->slack->textLimitWithBlocks = 60;

    expect(fn () => Cache::store('slack')->put('k', str_repeat('x', 200), 600))
        ->toThrow(SlackCacheException::class, 'chat.update stored different text');

    expect(Cache::store('slack')->get('k'))->toBe('short')
        ->and(Codec::decode($this->slack->latest('slackcache')['text'])[1])->toBe(serialize('short'));
});

it('deletes the thread with the key, leaving no placeholder', function (string $how) {
    $cache = Cache::store('slack');
    $cache->put('k', 'one', 600);
    $cache->put('k', 'two', 600);
    $cache->put('other', 'x', 10);
    $cache->put('other', 'y', 10);

    match ($how) {
        'forget' => $cache->forget('k'),
        'flush' => $cache->flush(),
        'reap' => (function () use ($cache) {
            $cache->forget('k');
            $this->travel(11)->seconds();
            $this->artisan('slackcache:reap')->assertSuccessful();
        })(),
    };

    $remaining = collect($this->slack->messages('slackcache'))->filter(fn ($m) => ($m['subtype'] ?? null) === 'tombstone');

    expect($remaining)->toBeEmpty();
})->with(['forget', 'flush', 'reap']);

it('posts a new message when another server deleted the key\'s message', function () {
    config()->set('cache.stores.slack_b', ['replica' => 'replica_b'] + config('cache.stores.slack'));
    config()->set('cache.stores.replica_b', ['driver' => 'array']);

    Cache::store('slack')->put('k', 'one', 600);
    Cache::store('slack_b')->forget('k');

    Cache::store('slack')->put('k', 'two', 600);

    expect(Cache::store('slack')->get('k'))->toBe('two')
        ->and($this->slack->messages('slackcache'))->toHaveCount(1);
});

it('keeps the write when the history reply fails', function () {
    Cache::store('slack')->put('k', 'one', 600);
    // The next chat.postMessage is the history reply, after chat.update has stored the new value.
    $this->slack->beforeNext('chat.postMessage', fn () => $this->slack->fail('internal_error'));

    Cache::store('slack')->put('k', 'two', 600);

    expect(Cache::store('slack')->get('k'))->toBe('two')
        ->and($this->slack->callCount('chat.update'))->toBe(1);
});

it('reposts a key whose message is past half its retention, so rewritten keys outlive the free plan', function () {
    $cache = Cache::store('slack');
    $cache->forever('k', 'one');
    $first = array_key_first($this->slack->messages('slackcache'));

    $this->travel(46)->days();
    $cache->forever('k', 'two');

    $messages = $this->slack->messages('slackcache');
    expect($messages)->toHaveCount(1)
        ->and(array_key_first($messages))->not->toBe($first);

    $this->travel(60)->days(); // 106 days after the first write
    expect($cache->get('k'))->toBe('two');
});
