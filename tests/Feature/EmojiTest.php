<?php

use HelgeSverre\SlackCache\Codec;
use HelgeSverre\SlackCache\Testing\FakeSlack;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->slack = FakeSlack::install();
    $this->travelTo(now()->setTimestamp(1_800_000_000));
    config()->set('cache.stores.slack.emoji', true);
});

/** @return list<string> */
function headerEmoji(FakeSlack $slack): array
{
    $section = $slack->latest('slackcache')['blocks'][0]['elements'][0]['elements'];

    return array_values(array_map(fn ($e) => $e['name'], array_filter($section, fn ($e) => $e['type'] === 'emoji')));
}

it('marks keys with a TTL and keys without one', function () {
    Cache::store('slack')->put('brief', 'v', 600);
    expect(headerEmoji($this->slack))->toBe(['hourglass_flowing_sand']);

    Cache::store('slack')->forever('forever', 'v');
    expect(headerEmoji($this->slack))->toBe(['infinity']);
});

it('marks locks', function () {
    Cache::store('slack')->lock('deploy', 60)->get();

    expect(headerEmoji($this->slack))->toBe(['lock']);
});

it('marks locks in a lock channel too', function () {
    config()->set('cache.stores.slack.lock_channel', 'slackcache-locks');
    Cache::store('slack')->lock('deploy', 60)->get();

    $section = $this->slack->latest('slackcache-locks')['blocks'][0]['elements'][0]['elements'];

    expect($section[0])->toBe(['type' => 'emoji', 'name' => 'lock']);
});

it('marks tagged, encrypted and compressed values', function () {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    config()->set('cache.stores.slack.encrypt', true);
    config()->set('cache.stores.slack.compress', true);

    Cache::store('slack')->tags(['reports'])->put('big', str_repeat('row ', 2_000), 600);

    expect(headerEmoji($this->slack))->toBe(['hourglass_flowing_sand', 'label', 'closed_lock_with_key', 'compression']);
});

it('keeps the emoji on increments and touches', function () {
    $cache = Cache::store('slack');
    $cache->increment('hits');
    expect(headerEmoji($this->slack))->toBe(['infinity']);

    $cache->getStore()->touch('hits', 60);
    expect(headerEmoji($this->slack))->toBe(['hourglass_flowing_sand']);
});

it('keeps the emoji through history edits and write-behind', function () {
    config()->set('cache.stores.slack.history', true);
    Cache::store('slack')->put('k', 'one', 600);
    Cache::store('slack')->forever('k', 'two');
    expect(headerEmoji($this->slack))->toBe(['infinity']);

    config()->set('cache.stores.slack.history', false);
    config()->set('cache.stores.slack.async', true);
    config()->set('queue.default', 'sync');
    app('cache')->forgetDriver('slack');
    Cache::store('slack')->put('async', 'v', 600);
    expect(headerEmoji($this->slack))->toBe(['hourglass_flowing_sand']);
});

it('adds no emoji unless asked', function () {
    config()->set('cache.stores.slack.emoji', false);
    Cache::store('slack')->put('k', 'v', 600);

    expect(headerEmoji($this->slack))->toBe([]);
});

it('leaves the canonical text alone', function () {
    Cache::store('slack')->put('k', 'v', 600);
    $withEmoji = $this->slack->latest('slackcache')['text'];

    expect(Codec::decode($withEmoji)[0])->toBe('k');
});
