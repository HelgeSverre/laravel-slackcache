<?php

use HelgeSverre\SlackCache\Entry;

$ninetyDays = 90 * 86_400_000;

it('orders Slack timestamps numerically', function () {
    expect(Entry::compareTs('1700000000.000010', '1700000000.000009'))->toBe(1)
        ->and(Entry::compareTs('999999999.999999', '1000000000.000001'))->toBe(-1)
        ->and(Entry::compareTs('1700000000.000001', '1700000000.000001'))->toBe(0);
});

it('expires keys without a TTL when the free plan hides their message', function () use ($ninetyDays) {
    $entry = new Entry('k', '1700000000.000001', 'v', null);

    expect($entry->deadline($ninetyDays))->toBe(1700000000_000 + $ninetyDays)
        ->and($entry->deadline(null))->toBeNull();
});

it('uses whichever comes first: the TTL or the free plan', function () use ($ninetyDays) {
    $soon = new Entry('k', '1700000000.000001', 'v', 1700000060_000);
    $late = new Entry('k', '1700000000.000001', 'v', 1900000000_000);

    expect($soon->deadline($ninetyDays))->toBe(1700000060_000)
        ->and($late->deadline($ninetyDays))->toBe(1700000000_000 + $ninetyDays)
        ->and($late->deadline(null))->toBe(1900000000_000);
});

it('round-trips through an array', function () {
    $entry = new Entry('k', '1.2', 'v', 5, 3);

    expect(Entry::fromArray($entry->toArray()))->toEqual($entry);
});

it('is not live at the exact moment it expires', function () {
    $entry = new Entry('k', '1800000000.000100', 'v', 1_800_000_060_000);

    expect($entry->isLive(1_800_000_059_999, null))->toBeTrue()
        ->and($entry->isLive(1_800_000_060_000, null))->toBeFalse();
});

it('compares Slack timestamps by seconds, then by the fraction as a number', function () {
    expect(Entry::compareTs('1800000000.000010', '1800000000.000009'))->toBe(1)
        ->and(Entry::compareTs('1800000000.000009', '1800000000.000010'))->toBe(-1)
        ->and(Entry::compareTs('999999999.999999', '1000000000.000000'))->toBe(-1)
        ->and(Entry::compareTs('1800000000', '1800000000.000001'))->toBe(-1)
        ->and(Entry::compareTs('1800000000.000001', '1800000000'))->toBe(1)
        ->and(Entry::compareTs('1800000000.5', '1800000000.5'))->toBe(0)
        ->and(Entry::tsSeconds('1800000000.999999'))->toBe(1_800_000_000)
        ->and(Entry::tsSeconds('1800000000'))->toBe(1_800_000_000);
});
