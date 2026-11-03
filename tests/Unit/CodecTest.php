<?php

use HelgeSverre\SlackCache\Codec;
use HelgeSverre\SlackCache\Exceptions\ValueTooLargeException;

// Fixtures produced by the original Python SlackCache (slackcache.codec.encode).
dataset('python fixtures', [
    'with ttl' => ['hello', 'world', 1760000000000, 'slackcache:v1 aGVsbG8= 1760000000000 d29ybGQ='],
    'no ttl' => ['counter', '42', null, 'slackcache:v1 Y291bnRlcg== - NDI='],
    'utf-8 key, binary value' => ['blåbærsyltetøy', "\x00\xff binary", null, 'slackcache:v1 YmzDpWLDpnJzeWx0ZXTDuHk= - AP8gYmluYXJ5'],
]);

it('encodes the same text as the Python server', function (string $key, string $value, ?int $expireAt, string $text) {
    expect(Codec::encode($key, $value, $expireAt)[0])->toBe($text);
})->with('python fixtures');

it('decodes text written by the Python server', function (string $key, string $value, ?int $expireAt, string $text) {
    expect(Codec::decode($text))->toBe([$key, $value, $expireAt, null]);
})->with('python fixtures');

it('ignores messages that are not cache entries', function (?string $text) {
    expect(Codec::decode($text))->toBeNull();
})->with([
    'null' => null,
    'empty' => '',
    'small talk' => 'has anyone seen my lunch',
    'wrong marker' => 'slackcache:v2 aGVsbG8= - d29ybGQ=',
    'bad base64' => 'slackcache:v1 !!! - d29ybGQ=',
    'bad expiry' => 'slackcache:v1 aGVsbG8= soon d29ybGQ=',
    'too many parts' => 'slackcache:v1 aGVsbG8= - d29ybGQ= extra',
]);

it('builds a readable preview for humans', function () {
    [, $blocks] = Codec::encode('greeting', 'hello there', 1760000000000);

    $section = $blocks[0]['elements'][0]['elements'];
    $preformatted = $blocks[0]['elements'][1]['elements'][0]['text'];

    expect($section[0]['text'])->toBe('SET ')
        ->and($section[1])->toMatchArray(['text' => 'greeting', 'style' => ['code' => true]])
        ->and($section[2]['text'])->toBe(' · expires 2025-10-09 08:53:20 UTC')
        ->and($preformatted)->toBe('hello there');
});

it('notes that keys without a TTL are evicted by the free plan', function () {
    [, $blocks] = Codec::encode('forever', 'and ever');

    expect($blocks[0]['elements'][0]['elements'][2]['text'])->toBe(' · no TTL (the free plan evicts it in 90 days)');
});

it('truncates long previews but never the canonical text', function () {
    $value = str_repeat('a', 5000);
    [$text, $blocks] = Codec::encode('long', $value);

    expect(Codec::decode($text)[1])->toBe($value)
        ->and($blocks[0]['elements'][1]['elements'][0]['text'])->toEndWith('… (5,000 bytes)');
});

it('marks empty values in the preview', function () {
    [, $blocks] = Codec::encode('empty', '');

    expect($blocks[0]['elements'][1]['elements'][0]['text'])->toBe('(empty string)');
});

it('refuses values that do not fit in one Slack message', function () {
    Codec::encode('big', str_repeat('x', 12_000));
})->throws(ValueTooLargeException::class, 'one Slack message holds 16,000');

it('accepts the largest value that fits', function () {
    // 16,000 - strlen('slackcache:v1 aw== - ') leaves 15,979 characters: 3,994 base64 quads, 11,982 bytes.
    expect(strlen(Codec::encode('k', str_repeat('x', 11_982))[0]))->toBe(15_997)
        ->and(fn () => Codec::encode('k', str_repeat('x', 11_983)))->toThrow(ValueTooLargeException::class);
});
