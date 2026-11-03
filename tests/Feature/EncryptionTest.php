<?php

use HelgeSverre\SlackCache\Codec;
use HelgeSverre\SlackCache\Testing\FakeSlack;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->slack = FakeSlack::install();
    $this->travelTo(now()->setTimestamp(1_800_000_000));
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    config()->set('cache.stores.slack.encrypt', true);
});

function storedValue(FakeSlack $slack): string
{
    return Codec::decode($slack->latest('slackcache')['text'])[1];
}

function previewText(FakeSlack $slack): string
{
    return $slack->latest('slackcache')['blocks'][0]['elements'][1]['elements'][0]['text'];
}

it('encrypts values in the channel', function () {
    Cache::store('slack')->put('secret', 'hunter2', 600);

    expect(Cache::store('slack')->get('secret'))->toBe('hunter2')
        ->and(storedValue($this->slack))->toStartWith('SC1E:')
        ->and(storedValue($this->slack))->not->toContain('hunter2')
        ->and(previewText($this->slack))->toBe('(encrypted)');
});

it('encrypts numbers and still increments them', function () {
    $cache = Cache::store('slack');
    $cache->put('hits', 41, 600);

    expect($cache->increment('hits'))->toBe(42)
        ->and(storedValue($this->slack))->toStartWith('SC1E:')
        ->and($cache->get('hits'))->toEqual(42);
});

it('keeps history replies encrypted too', function () {
    config()->set('cache.stores.slack.history', true);
    Cache::store('slack')->put('secret', 'one', 600);
    $ts = array_key_first($this->slack->messages('slackcache'));
    Cache::store('slack')->put('secret', 'two', 600);

    $reply = array_values($this->slack->replies('slackcache', $ts))[0]['text'];

    expect($reply)->toContain('(encrypted)')->not->toContain('one');
});

it('reads values written before encryption was turned on', function () {
    config()->set('cache.stores.slack.encrypt', false);
    Cache::store('slack')->forever('legacy', 'plain');
    app('cache')->forgetDriver('slack');
    config()->set('cache.stores.slack.encrypt', true);

    expect(Cache::store('slack')->get('legacy'))->toBe('plain');
});

it('treats a value it cannot decrypt as a miss', function () {
    Cache::store('slack')->put('secret', 'hunter2', 600);
    app('cache')->forgetDriver('slack');
    Cache::store('replica')->flush();
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));
    app()->forgetInstance('encrypter');

    expect(Cache::store('slack')->get('secret'))->toBeNull();
});
