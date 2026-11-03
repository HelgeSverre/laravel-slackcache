<?php

use HelgeSverre\SlackCache\Testing\FakeSlack;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->slack = FakeSlack::install();
    $this->travelTo(now()->setTimestamp(1_800_000_000));
});

function previewHeader(FakeSlack $slack): string
{
    $section = $slack->latest('slackcache')['blocks'][0]['elements'][0]['elements'];

    return implode('', array_column($section, 'text'));
}

it('supports cache tags', function () {
    $cache = Cache::store('slack');

    $cache->tags(['people', 'artists'])->put('John', 'Doe', 600);
    $cache->tags(['people', 'authors'])->put('Anne', 'Smith', 600);

    expect($cache->tags(['people', 'artists'])->get('John'))->toBe('Doe')
        ->and($cache->tags(['people', 'authors'])->get('Anne'))->toBe('Smith')
        ->and($cache->get('John'))->toBeNull();
});

it('shows the tags in the Slack preview', function () {
    Cache::store('slack')->tags(['people', 'artists'])->put('John', 'Doe', 600);

    expect(previewHeader($this->slack))->toContain('· tags: people, artists');
});

it('does not show tags on untagged writes after a tagged one', function () {
    Cache::store('slack')->tags(['people'])->put('John', 'Doe', 600);
    Cache::store('slack')->put('plain', 'value', 600);

    expect(previewHeader($this->slack))->not->toContain('tags:');
});

it('flushes a tag and deletes its messages', function () {
    $cache = Cache::store('slack');
    $cache->tags(['people'])->put('John', 'Doe', 600);
    $cache->tags(['people'])->forever('Jane', 'Roe');
    $cache->put('untagged', 'stays', 600);

    $cache->tags(['people'])->flush();

    expect($cache->tags(['people'])->get('John'))->toBeNull()
        ->and($cache->tags(['people'])->get('Jane'))->toBeNull()
        ->and($cache->get('untagged'))->toBe('stays')
        ->and(collect($this->slack->messages('slackcache'))->pluck('text')->filter(fn ($t) => str_starts_with($t, 'slackcache:v1'))->count())
        ->toBe(2); // 'untagged' and the tag's version key
});

it('invalidates items under a combination of tags when one of them is flushed', function () {
    $cache = Cache::store('slack');
    $cache->tags(['people', 'authors'])->put('Anne', 'Smith', 600);

    $cache->tags(['authors'])->flush();

    expect($cache->tags(['people', 'authors'])->get('Anne'))->toBeNull();
});

it('increments tagged counters', function () {
    expect(Cache::store('slack')->tags(['stats'])->increment('views', 3))->toBe(3)
        ->and(Cache::store('slack')->tags(['stats'])->increment('views'))->toBe(4);
});

it('remembers sections, the Laravel 4.0 name for tags', function () {
    $cache = Cache::store('slack');

    $cache->section('people')->put('John', 'Doe', 600);

    expect($cache->section('people')->get('John'))->toBe('Doe')
        ->and($cache->tags(['people'])->get('John'))->toBe('Doe');
});

it('answers section() on the Cache facade when slack is the default store', function () {
    config()->set('cache.default', 'slack');

    Cache::section('people')->put('John', 'Doe', 600);

    expect(Cache::tags(['people'])->get('John'))->toBe('Doe');
});
