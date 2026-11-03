<?php

use HelgeSverre\SlackCache\Codec;
use HelgeSverre\SlackCache\Exceptions\SlackApiException;
use HelgeSverre\SlackCache\Exceptions\SlackCacheException;
use HelgeSverre\SlackCache\Exceptions\ValueTooLargeException;
use HelgeSverre\SlackCache\SlackStore;
use HelgeSverre\SlackCache\Testing\FakeSlack;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

beforeEach(function () {
    $this->slack = FakeSlack::install();
    $this->travelTo(now()->setTimestamp(1_800_000_000));
});

it('registers the slack driver', function () {
    expect(Cache::store('slack')->getStore())->toBeInstanceOf(SlackStore::class);
});

it('stores and reads a value through Slack', function () {
    Cache::store('slack')->put('hello', 'world', 60);

    expect(Cache::store('slack')->get('hello'))->toBe('world');

    $messages = $this->slack->messages('slackcache');
    expect($messages)->toHaveCount(1)
        ->and(Codec::decode(reset($messages)['text']))->toBe(['hello', serialize('world'), 1_800_000_060_000, null]);
});

it('creates and joins the channel on first use', function () {
    Cache::store('slack')->put('a', 1, 60);

    expect($this->slack->channelId('slackcache'))->not->toBeNull()
        ->and($this->slack->callCount('conversations.create'))->toBe(1)
        ->and($this->slack->callCount('conversations.join'))->toBe(1);
});

it('reuses an existing channel', function () {
    $id = $this->slack->createChannel('slackcache');

    Cache::store('slack')->put('a', 1, 60);

    expect($this->slack->callCount('conversations.create'))->toBe(0)
        ->and($this->slack->messages($id))->toHaveCount(1);
});

it('accepts a channel ID and skips listing the workspace', function () {
    $id = $this->slack->createChannel('anything');
    config()->set('cache.stores.slack.channel', $id);

    Cache::store('slack')->put('a', 1, 60);

    expect($this->slack->callCount('conversations.list'))->toBe(0)
        ->and($this->slack->messages($id))->toHaveCount(1);
});

it('keeps one message per live key', function () {
    $cache = Cache::store('slack');

    $cache->put('k', 'one', 60);
    $cache->put('k', 'two', 60);
    $cache->put('k', 'three', 60);

    expect($cache->get('k'))->toBe('three')
        ->and($this->slack->messages('slackcache'))->toHaveCount(1);
});

it('round-trips PHP values', function (mixed $value) {
    Cache::store('slack')->forever('value', $value);

    expect(Cache::store('slack')->get('value'))->toEqual($value);
})->with([
    'string' => 'plain',
    'array' => [['nested' => true, 'list' => [1, 2, 3]]],
    'null-ish false' => false,
    'object' => fn () => (object) ['a' => 1],
    'unicode' => '🦀 blåbær',
]);

it('stores numbers as plain text, readable in Slack', function () {
    Cache::store('slack')->forever('n', 42);

    $text = $this->slack->latest('slackcache')['text'];

    expect(Codec::decode($text)[1])->toBe('42')
        ->and(Cache::store('slack')->get('n'))->toEqual(42);
});

it('returns values written by other clients as raw strings', function () {
    // As redis-cli SET hello world through the Python server would write it.
    $this->slack->createChannel('slackcache');
    Cache::store('slack')->put('warmup', 1, 60);
    Cache::store('slack')->forget('warmup');
    $this->slack->postAs('slackcache', 'slackcache:v1 aGVsbG8= - d29ybGQ=', ['bot_id' => $this->slack->botId]);

    $this->artisan('slackcache:load')->assertSuccessful();

    expect(Cache::store('slack')->get('hello'))->toBe('world');
});

it('returns null for missing keys', function () {
    expect(Cache::store('slack')->get('missing'))->toBeNull()
        ->and(Cache::store('slack')->get('missing', 'default'))->toBe('default');
});

it('expires keys by TTL', function () {
    Cache::store('slack')->put('brief', 'moment', 10);

    $this->travel(9)->seconds();
    expect(Cache::store('slack')->get('brief'))->toBe('moment');

    $this->travel(2)->seconds();
    expect(Cache::store('slack')->get('brief'))->toBeNull();
});

it('evicts keys without a TTL after the free plan retention period', function () {
    Cache::store('slack')->forever('legacy', 'data');

    $this->travel(89)->days();
    expect(Cache::store('slack')->get('legacy'))->toBe('data');

    $this->travel(2)->days();
    expect(Cache::store('slack')->get('legacy'))->toBeNull();
});

it('keeps keys forever when retention is disabled', function () {
    config()->set('cache.stores.slack.retention_days', null);

    Cache::store('slack')->forever('paid', 'plan');
    $this->travel(400)->days();

    expect(Cache::store('slack')->get('paid'))->toBe('plan');
});

it('forgets keys and deletes their message', function () {
    $cache = Cache::store('slack');
    $cache->put('k', 'v', 60);

    expect($cache->forget('k'))->toBeTrue()
        ->and($cache->forget('k'))->toBeFalse()
        ->and($cache->get('k'))->toBeNull()
        ->and($this->slack->messages('slackcache'))->toBeEmpty();
});

it('treats a zero or negative TTL as a delete', function () {
    $cache = Cache::store('slack');
    $cache->put('k', 'v', 60);
    $cache->put('k', 'v', 0);

    expect($cache->get('k'))->toBeNull()
        ->and($this->slack->messages('slackcache'))->toBeEmpty();
});

it('increments and decrements, keeping the TTL', function () {
    $cache = Cache::store('slack');

    expect($cache->increment('hits'))->toBe(1)
        ->and($cache->increment('hits', 5))->toBe(6)
        ->and($cache->decrement('hits', 2))->toBe(4);

    $cache->put('ttl', 10, 30);
    $cache->increment('ttl');
    $this->travel(31)->seconds();

    expect($cache->get('ttl'))->toBeNull()
        ->and($this->slack->messages('slackcache'))->toHaveCount(2);
});

it('refuses to increment a non-integer', function () {
    Cache::store('slack')->put('word', 'bird', 60);

    expect(Cache::store('slack')->increment('word'))->toBeFalse();
});

it('adds only when the key is missing', function () {
    $cache = Cache::store('slack');

    expect($cache->add('once', 'first', 60))->toBeTrue()
        ->and($cache->add('once', 'second', 60))->toBeFalse()
        ->and($cache->get('once'))->toBe('first');
});

it('touches a key to extend its TTL', function () {
    $cache = Cache::store('slack');
    $cache->put('k', 'v', 10);

    expect($cache->getStore()->touch('k', 100))->toBeTrue()
        ->and($cache->getStore()->touch('missing', 100))->toBeFalse();

    $this->travel(50)->seconds();
    expect($cache->get('k'))->toBe('v');
});

it('reads and writes many keys', function () {
    $cache = Cache::store('slack');
    $cache->putMany(['a' => 1, 'b' => 2], 60);

    expect($cache->many(['a', 'b', 'c']))->toEqual(['a' => 1, 'b' => 2, 'c' => null]);
});

it('remembers', function () {
    $calls = 0;
    $compute = function () use (&$calls) {
        $calls++;

        return 'expensive';
    };

    Cache::store('slack')->remember('r', 60, $compute);
    Cache::store('slack')->remember('r', 60, $compute);

    expect($calls)->toBe(1);
});

it('applies the cache prefix', function () {
    config()->set('cache.stores.slack.prefix', 'app:');
    Cache::store('slack')->put('k', 'v', 60);

    $text = $this->slack->latest('slackcache')['text'];
    expect(Codec::decode($text)[0])->toBe('app:k');
});

it('flushes cache messages and leaves the conversation alone', function () {
    $cache = Cache::store('slack');
    $cache->put('a', 1, 60);
    $cache->put('b', 2, 60);
    $this->slack->postAs('slackcache', 'who is posting all these base64 messages?', ['user' => 'U0HUMAN']);

    expect($cache->flush())->toBeTrue()
        ->and($cache->get('a'))->toBeNull()
        ->and($this->slack->messages('slackcache'))->toHaveCount(1)
        ->and($this->slack->latest('slackcache')['text'])->toContain('who is posting');
});

it('ignores cache-shaped messages from other bots and users', function () {
    $this->slack->createChannel('slackcache');
    $this->slack->postAs('slackcache', 'slackcache:v1 aGVsbG8= - d29ybGQ=', ['user' => 'U0INTRUDER']);
    $this->slack->postAs('slackcache', 'slackcache:v1 aGVsbG8= - d29ybGQ=', ['bot_id' => 'B0OTHER']);

    expect(Cache::store('slack')->get('hello'))->toBeNull();
});

it('refuses values larger than one Slack message', function () {
    Cache::store('slack')->put('big', str_repeat('x', 20_000), 60);
})->throws(ValueTooLargeException::class);

it('rolls back a write that Slack truncated', function () {
    $this->slack->textLimitWithBlocks = 100;

    expect(fn () => Cache::store('slack')->put('k', str_repeat('x', 500), 60))
        ->toThrow(SlackCacheException::class, 'stored different text');

    expect($this->slack->messages('slackcache'))->toBeEmpty();
});

it('waits out rate limits', function () {
    Sleep::fake();
    Cache::store('slack')->put('warm', 1, 60);

    $this->slack->rateLimit(times: 2, retryAfter: 3);
    Cache::store('slack')->put('k', 'v', 60);

    Sleep::assertSleptTimes(2);
    Sleep::assertSequence([
        Sleep::for(3)->seconds(),
        Sleep::for(3)->seconds(),
    ]);
    expect(Cache::store('slack')->get('k'))->toBe('v');
});

it('gives up after the configured retries', function () {
    Sleep::fake();
    config()->set('cache.stores.slack.retries', 1);
    Cache::store('slack')->put('warm', 1, 60);

    $this->slack->rateLimit(times: 5);

    Cache::store('slack')->put('k', 'v', 60);
})->throws(SlackApiException::class, 'chat.postMessage failed: http_429');

it('reports Slack errors without leaking the token', function () {
    $this->slack->fail('invalid_auth');

    try {
        Cache::store('slack')->get('k');
        $this->fail('Expected an exception');
    } catch (SlackApiException $e) {
        expect($e->getMessage())->toBe('Slack API call auth.test failed: invalid_auth')
            ->and($e->getMessage())->not->toContain('xoxb');
    }
});

it('requires a token', function () {
    config()->set('cache.stores.slack.token', null);

    Cache::store('slack');
})->throws(SlackCacheException::class, 'needs a token');

it('refuses to replicate onto itself', function () {
    config()->set('cache.stores.slack.replica', 'slack');

    Cache::store('slack');
})->throws(SlackCacheException::class, 'Slack all the way down');

it('provides atomic locks', function () {
    $lock = Cache::store('slack')->lock('deploy', 60);

    expect($lock->get())->toBeTrue()
        ->and(Cache::store('slack')->lock('deploy', 60)->get())->toBeFalse();

    $lock->release();

    expect(Cache::store('slack')->lock('deploy', 60)->get())->toBeTrue();
});

it('shows people a readable preview of the value', function (mixed $value, string $preview) {
    Cache::store('slack')->forever('p', $value);

    $blocks = $this->slack->latest('slackcache')['blocks'];

    expect($blocks[0]['elements'][1]['elements'][0]['text'])->toBe($preview);
})->with([
    'string' => ['Hello from Laravel', 'Hello from Laravel'],
    'array' => [['theme' => 'dark', 'regrets' => 0], "{\n    \"theme\": \"dark\",\n    \"regrets\": 0\n}"],
    'bool' => [true, 'true'],
    'object' => [new ArrayObject([1]), 'ArrayObject (serialized)'],
]);
