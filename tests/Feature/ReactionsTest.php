<?php

use HelgeSverre\SlackCache\Codec;
use HelgeSverre\SlackCache\Testing\FakeSlack;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->slack = FakeSlack::install();
    $this->travelTo(now()->setTimestamp(1_800_000_000));
    config()->set('cache.stores.slack.reactions', ['hot' => 3, 'expiring' => 3600]);
});

/** Our reactions per key, read from the fake's messages. */
function reactionsByKey(FakeSlack $slack, string $channel = 'slackcache'): array
{
    $result = [];

    foreach ($slack->messages($channel) as $message) {
        $key = Codec::decode($message['text'] ?? '')[0] ?? null;

        if ($key !== null) {
            $names = array_column(array_filter($message['reactions'] ?? [], fn ($r) => in_array($slack->userId, $r['users'], true)), 'name');
            sort($names);
            $result[$key] = $names;
        }
    }

    ksort($result);

    return $result;
}

it('marks keys that expire soon, have expired, or are read a lot', function () {
    $cache = Cache::store('slack');
    $cache->put('soon', 'v', 600);
    $cache->put('later', 'v', 86_400);
    $cache->put('gone', 'v', 10);
    $cache->forever('hot', 'v');
    $cache->get('hot');
    $cache->get('hot');
    $cache->get('hot');
    $this->travel(11)->seconds();

    $this->artisan('slackcache:decorate')->expectsOutputToContain('Added 3 reactions, removed 0')->assertSuccessful();

    expect(reactionsByKey($this->slack))->toBe([
        'gone' => ['skull'],
        'hot' => ['fire'],
        'later' => [],
        'soon' => ['hourglass'],
    ]);
});

it('does nothing the second time', function () {
    Cache::store('slack')->put('soon', 'v', 600);
    $this->artisan('slackcache:decorate')->assertSuccessful();
    $calls = $this->slack->callCount('reactions.add') + $this->slack->callCount('reactions.remove');

    $this->artisan('slackcache:decorate')->expectsOutputToContain('Added 0 reactions, removed 0')->assertSuccessful();

    expect($this->slack->callCount('reactions.add') + $this->slack->callCount('reactions.remove'))->toBe($calls);
});

it('removes reactions that no longer apply', function () {
    config()->set('cache.stores.slack.history', true); // Edits keep the message, and its reactions.
    $cache = Cache::store('slack');
    $cache->put('k', 'v', 600);
    $this->artisan('slackcache:decorate')->assertSuccessful();
    expect(reactionsByKey($this->slack)['k'])->toBe(['hourglass']);

    $cache->forever('k', 'v2');
    $this->artisan('slackcache:decorate')->expectsOutputToContain('removed 1')->assertSuccessful();

    expect(reactionsByKey($this->slack)['k'])->toBe([]);
});

it('refuses to decorate unless reactions are on', function () {
    config()->set('cache.stores.slack.reactions', false);

    $this->artisan('slackcache:decorate')->expectsOutputToContain('reactions option')->assertFailed();
});

it('counts reads per message, so a rewritten key starts cold', function () {
    $cache = Cache::store('slack');
    $cache->forever('k', 'v');
    $cache->get('k');
    $cache->get('k');
    $cache->get('k');
    $cache->forever('k', 'new');

    $this->artisan('slackcache:decorate')->assertSuccessful();

    expect(reactionsByKey($this->slack)['k'])->toBe([]);
});

it('stays within the call budget', function () {
    foreach (range(1, 5) as $i) {
        Cache::store('slack')->put("soon:{$i}", 'v', 600);
    }

    $this->artisan('slackcache:decorate', ['--limit' => 2])->expectsOutputToContain('Added 2 reactions')->assertSuccessful();
    $this->artisan('slackcache:decorate', ['--limit' => 10])->expectsOutputToContain('Added 3 reactions')->assertSuccessful();
});

it('leaves other people\'s reactions alone', function () {
    Cache::store('slack')->forever('k', 'v');
    $ts = array_key_first($this->slack->messages('slackcache'));
    $this->slack->reactAs('U0HUMAN', 'slackcache', $ts, 'hourglass');
    $this->slack->reactAs('U0HUMAN', 'slackcache', $ts, 'eyes');

    $this->artisan('slackcache:decorate')->expectsOutputToContain('Added 0 reactions, removed 0')->assertSuccessful();

    $reactions = collect($this->slack->messages('slackcache')[$ts]['reactions'])->pluck('name')->sort()->values()->all();
    expect($reactions)->toBe(['eyes', 'hourglass']);
});

it('decorates the lock channel too', function () {
    config()->set('cache.stores.slack.lock_channel', 'slackcache-locks');
    Cache::store('slack')->lock('deploy', 60)->get();

    $this->artisan('slackcache:decorate')->assertSuccessful();

    expect(reactionsByKey($this->slack, 'slackcache-locks'))->toBe(['deploy' => ['hourglass']]);
});

it('lets the reaper clear what the skull marked', function () {
    Cache::store('slack')->put('gone', 'v', 10);
    $this->travel(11)->seconds();
    $this->artisan('slackcache:decorate')->assertSuccessful();

    $this->artisan('slackcache:reap')->assertSuccessful();

    expect($this->slack->messages('slackcache'))->toBeEmpty();
});
