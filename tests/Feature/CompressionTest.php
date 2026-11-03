<?php

use HelgeSverre\SlackCache\Codec;
use HelgeSverre\SlackCache\Testing\FakeSlack;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->slack = FakeSlack::install();
    $this->travelTo(now()->setTimestamp(1_800_000_000));
    config()->set('cache.stores.slack.compress', true);
});

it('compresses values so larger ones fit in a message', function () {
    $value = str_repeat('{"id": 1, "name": "A rather repetitive row"}, ', 1_000); // ~46 KB

    Cache::store('slack')->put('big', $value, 600);

    $text = $this->slack->latest('slackcache')['text'];

    expect(Cache::store('slack')->get('big'))->toBe($value)
        ->and(Codec::decode($text)[1])->toStartWith('SC1Z:')
        ->and(strlen($text))->toBeLessThan(Codec::MAX_TEXT);
});

it('keeps the readable preview for compressed values', function () {
    Cache::store('slack')->put('big', str_repeat('hello ', 500), 600);

    expect($this->slack->latest('slackcache')['blocks'][0]['elements'][1]['elements'][0]['text'])->toStartWith('hello hello');
});

it('stores small or incompressible values as they are', function () {
    Cache::store('slack')->put('tiny', 'hi', 600);

    expect(Codec::decode($this->slack->latest('slackcache')['text'])[1])->toBe(serialize('hi'));
});

it('compresses, then encrypts', function () {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    config()->set('cache.stores.slack.encrypt', true);
    $value = str_repeat('secret ', 2_000);

    Cache::store('slack')->put('both', $value, 600);

    expect(Cache::store('slack')->get('both'))->toBe($value)
        ->and(Codec::decode($this->slack->latest('slackcache')['text'])[1])->toStartWith('SC1E:');
});

it('still reads compressed values after compression is turned off', function () {
    $value = str_repeat('abc', 3_000);
    Cache::store('slack')->forever('k', $value);
    app('cache')->forgetDriver('slack');
    config()->set('cache.stores.slack.compress', false);

    expect(Cache::store('slack')->get('k'))->toBe($value);
});
