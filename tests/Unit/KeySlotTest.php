<?php

use HelgeSverre\SlackCache\KeySlot;

// Expected slots from the original Python server (slackcache.store.key_slot), which match Redis CLUSTER KEYSLOT.
it('computes Redis Cluster hash slots', function (string $key, int $slot) {
    expect(KeySlot::for($key))->toBe($slot);
})->with([
    ['foo', 12182],
    ['123456789', 12739],
    ['{user1000}.following', 3443],
    ['user1000', 3443],
    ['', 0],
]);

it('uses the CRC16/XMODEM check value', function () {
    expect(KeySlot::crc16('123456789'))->toBe(0x31C3);
});

it('ignores empty hash tags', function () {
    expect(KeySlot::for('{}foo'))->toBe(KeySlot::crc16('{}foo') % KeySlot::SLOTS);
});

it('keeps hash-tagged keys on the same shard', function () {
    expect(KeySlot::shard('{cart:42}:items', 8))->toBe(KeySlot::shard('{cart:42}:total', 8));
});

it('spreads keys over every shard', function () {
    $shards = collect(range(1, 400))->map(fn ($i) => KeySlot::shard("key:{$i}", 8))->unique()->sort()->values()->all();

    expect($shards)->toBe(range(0, 7));
});
