<?php

use HelgeSverre\SlackCache\Keyspace;
use HelgeSverre\SlackCache\Testing\FakeSlack;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->slack = FakeSlack::install();
    $this->travelTo(now()->setTimestamp(1_800_000_000));
});

it('sets the channel topic to a count of what the channel holds', function () {
    $cache = Cache::store('slack');
    $cache->put('soon', 'v', 600);
    $cache->put('later', 'v', 86_400);
    $cache->forever('always', 'v');

    $this->artisan('slackcache:topic')->expectsOutputToContain('Updated 1 topic.')->assertSuccessful();

    expect($this->slack->topic('slackcache'))
        ->toBe('3 keys · 1 expires within the hour · 1 never expires · Cache storage. Please do not chat here; it is not that kind of channel.');
});

it('deletes Slack\'s "set the channel topic" notice unless asked to keep it', function () {
    Cache::store('slack')->put('k', 'v', 60);

    $this->artisan('slackcache:topic')->assertSuccessful();
    expect(array_column($this->slack->messages('slackcache'), 'subtype'))->toBe([]);

    Cache::store('slack')->put('k2', 'v', 60);
    $this->artisan('slackcache:topic', ['--keep-notice' => true])->assertSuccessful();
    expect(array_column($this->slack->messages('slackcache'), 'subtype'))->toBe(['channel_topic']);
});

it('leaves a current topic alone', function () {
    Cache::store('slack')->put('k', 'v', 60);
    $this->artisan('slackcache:topic')->assertSuccessful();
    $calls = $this->slack->callCount('conversations.setTopic');

    $this->artisan('slackcache:topic')->expectsOutputToContain('Every topic was already current.')->assertSuccessful();

    expect($this->slack->callCount('conversations.setTopic'))->toBe($calls);
});

it('describes each shard of a sharded store', function () {
    config()->set('cache.stores.slack.shards', 2);
    $cache = Cache::store('slack');

    foreach (range(1, 6) as $i) {
        $cache->forever("key-{$i}", 'v');
    }

    $this->artisan('slackcache:topic')->expectsOutputToContain('Updated 2 topics.')->assertSuccessful();

    $topics = [$this->slack->topic('slackcache-1'), $this->slack->topic('slackcache-2')];
    $counts = array_map(fn ($t) => (int) explode(' ', explode(' · ', $t)[1])[0], $topics);

    expect($topics[0])->toStartWith('Shard 1 of 2 · ')
        ->and($topics[1])->toStartWith('Shard 2 of 2 · ')
        ->and(array_sum($counts))->toBe(6);
});

it('says singular and plural correctly, and skips zero counts', function () {
    $row = fn (int $keys, int $expiring, int $forever) => ['channel' => null, 'keys' => $keys, 'expiring' => $expiring, 'forever' => $forever];

    expect(Keyspace::defaultTopic($row(0, 0, 0), 1, 1))->toStartWith('0 keys · Cache storage.')
        ->and(Keyspace::defaultTopic($row(1, 0, 1), 1, 1))->toStartWith('1 key · 1 never expires · ')
        ->and(Keyspace::defaultTopic($row(1200, 2, 3), 1, 1))->toStartWith('1,200 keys · 2 expire within the hour · 3 never expire · ');
});

it('takes a custom format, cut to Slack\'s 250 characters', function () {
    Cache::store('slack')->put('k', 'v', 60);

    Cache::store('slack')->getStore()->keyspace()->updateTopics(fn (array $row) => "{$row['keys']} ".str_repeat('x', 300));

    expect(mb_strlen($this->slack->topic('slackcache')))->toBe(250)
        ->and($this->slack->topic('slackcache'))->toStartWith('1 xxx')->toEndWith('…');
});
