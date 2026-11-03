<?php

use HelgeSverre\SlackCache\Codec;
use HelgeSverre\SlackCache\Events\SlackRequestRetrying;
use HelgeSverre\SlackCache\Exceptions\ValueTooLargeException;
use HelgeSverre\SlackCache\SlackCache;
use HelgeSverre\SlackCache\Testing\FakeSlack;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    $this->slack = FakeSlack::install();
    config()->set('cache.stores.slack.files', true);
    $this->big = random_bytes(40_000); // About 53,000 characters of base64: three messages' worth.
});

/** Forget the store and its replica, so the next read rebuilds from Slack. */
function coldSlackStore(): Repository
{
    Cache::store('replica')->flush();
    app('cache')->forgetDriver('slack');

    return Cache::store('slack');
}

it('stores a value too large for a message as a file, and reads it back', function () {
    Cache::store('slack')->put('report', $this->big, 600);

    expect($this->slack->files)->toHaveCount(1)
        ->and(Cache::store('slack')->get('report'))->toBe($this->big);

    $file = array_key_first($this->slack->files);
    $text = $this->slack->latest('slackcache')['text'];

    expect($text)->toBe(implode(' ', ['slackcache:v1', base64_encode('report'), Codec::decode($text)[2], '@'.$file.':'.hash('sha256', serialize($this->big))]))
        ->and(strlen($text))->toBeLessThan(200)
        ->and($this->slack->files[$file]['bytes'])->toBe(serialize($this->big))
        ->and($this->slack->files[$file]['name'])->toBe('slackcache-'.substr(sha1('report'), 0, 12).'.bin')
        ->and($this->slack->files[$file]['title'])->toBe('report');
});

it('says so in the preview, with a paperclip when emoji is on', function () {
    config()->set('cache.stores.slack.emoji', true);
    Cache::store('slack')->put('report', $this->big, 600);

    $header = $this->slack->latest('slackcache')['blocks'][0]['elements'][0]['elements'];

    expect(array_column(array_filter($header, fn ($e) => $e['type'] === 'emoji'), 'name'))->toBe(['hourglass_flowing_sand', 'paperclip'])
        ->and(end($header)['text'])->toContain(' · stored as a file, '.number_format(strlen(serialize($this->big))).' bytes');
});

it('keeps small values in the message', function () {
    Cache::store('slack')->put('small', 'fits', 600);

    expect($this->slack->files)->toBe([])
        ->and($this->slack->callCount('files.getUploadURLExternal'))->toBe(0);
});

it('rebuilds a cold replica by downloading the file', function () {
    Cache::store('slack')->put('report', $this->big, 600);

    expect(coldSlackStore()->get('report'))->toBe($this->big)
        ->and($this->slack->callCount('files.info'))->toBe(1);
});

it('does not download a file again while its message is unchanged', function () {
    config()->set('cache.stores.slack.sync_interval', 30);
    $this->travelTo(now()->setTimestamp(1_800_000_000));
    Cache::store('slack')->put('report', $this->big, 3600);
    $cache = coldSlackStore();
    $cache->get('report');

    $this->travel(31)->seconds();

    expect($cache->get('report'))->toBe($this->big)
        ->and($this->slack->callCount('files.info'))->toBe(1);
});

it('treats a deleted or tampered file as a miss', function () {
    Cache::store('slack')->put('gone', $this->big, 600);
    Cache::store('slack')->put('tampered', $this->big.'x', 600);
    [$gone, $tampered] = array_keys($this->slack->files);

    unset($this->slack->files[$gone]);
    $this->slack->corruptFile($tampered, serialize('evil'));

    $cold = coldSlackStore();

    expect($cold->get('gone'))->toBeNull()
        ->and($cold->get('tampered'))->toBeNull();
});

it('deletes the file when the key is replaced, forgotten, reaped or flushed', function () {
    $this->travelTo(now()->setTimestamp(1_800_000_000));
    $cache = Cache::store('slack');

    $cache->put('replaced', $this->big, 600);
    $cache->put('replaced', $this->big.'2', 600);
    expect($this->slack->files)->toHaveCount(1);

    $cache->put('replaced', 'small now', 600);
    expect($this->slack->files)->toBe([]);

    $cache->put('forgotten', $this->big, 600);
    $cache->forget('forgotten');
    expect($this->slack->files)->toBe([]);

    $cache->put('expires', $this->big, 10);
    $this->travel(11)->seconds();
    $this->artisan('slackcache:reap')->assertSuccessful();
    expect($this->slack->files)->toBe([]);

    $cache->put('a', $this->big, 600);
    $cache->put('b', $this->big, 600);
    $cache->flush();
    expect($this->slack->files)->toBe([])->and($this->slack->messages('slackcache'))->toBe([]);
});

it('deletes the file of a key another server replaced, at the next reload', function () {
    config()->set('cache.stores.other', ['replica' => 'other-replica'] + config('cache.stores.slack'));
    config()->set('cache.stores.other-replica', ['driver' => 'array', 'serialize' => true]);
    $other = Cache::store('other');
    $other->get('anything'); // The other server has loaded the channel, and will not see this server's write.

    Cache::store('slack')->put('report', $this->big, 600);
    $other->put('report', $this->big.'newer', 600);
    expect($this->slack->files)->toHaveCount(2)->and($this->slack->messages('slackcache'))->toHaveCount(2);

    expect(coldSlackStore()->get('report'))->toBe($this->big.'newer')
        ->and($this->slack->files)->toHaveCount(1)
        ->and($this->slack->messages('slackcache'))->toHaveCount(1);
});

it('deletes the uploaded file when the post fails', function () {
    $cache = Cache::store('slack');
    $cache->put('warmup', 'v', 60);

    $this->slack->beforeNext('chat.postMessage', fn () => $this->slack->fail('channel_not_found'));
    expect(fn () => $cache->put('report', $this->big, 600))->toThrow(Exception::class, 'channel_not_found');
    expect($this->slack->files)->toBe([]);

    $this->slack->beforeNext('files.completeUploadExternal', fn () => $this->slack->fail('upload_not_found'));
    expect(fn () => $cache->put('report', $this->big, 600))->toThrow(Exception::class, 'upload_not_found');
    expect($this->slack->files)->toBe([]);
});

it('still throws above max_bytes, and when files are off', function () {
    config()->set('cache.stores.slack.files', ['max_bytes' => 30_000]);

    expect(fn () => Cache::store('slack')->put('report', $this->big, 600))
        ->toThrow(ValueTooLargeException::class, 'the files option stores at most 30,000');

    config()->set('cache.stores.slack.files', false);
    app('cache')->forgetDriver('slack');

    expect(fn () => Cache::store('slack')->put('report', $this->big, 600))
        ->toThrow(ValueTooLargeException::class, 'Turn on compress or files');
});

it('works with write-behind', function () {
    config()->set('cache.stores.slack.async', true);
    config()->set('queue.default', 'sync');

    Cache::store('slack')->put('report', $this->big, 600);
    Cache::store('slack')->put('report', $this->big.'2', 600);

    expect($this->slack->files)->toHaveCount(1)
        ->and(coldSlackStore()->get('report'))->toBe($this->big.'2');
});

it('reposts instead of editing in history mode when a file is involved', function () {
    config()->set('cache.stores.slack.history', true);
    $cache = Cache::store('slack');

    $cache->put('k', 'small', 600);
    $first = array_key_first($this->slack->messages('slackcache'));
    $cache->put('k', $this->big, 600);
    $second = array_key_first($this->slack->messages('slackcache'));
    $cache->put('k', 'small again', 600);

    expect($second)->not->toBe($first)
        ->and($this->slack->callCount('chat.update'))->toBe(0)
        ->and($this->slack->messages('slackcache'))->toHaveCount(1)
        ->and($this->slack->files)->toBe([])
        ->and($cache->get('k'))->toBe('small again');

    $cache->put('k', 'edited in place', 600);
    expect($this->slack->callCount('chat.update'))->toBe(1);
});

it('retries a failed upload or download', function () {
    Event::fake([SlackRequestRetrying::class]);
    $cache = Cache::store('slack');
    $cache->put('warmup', 'v', 60);

    $this->slack->fileOutage(1, 503);
    $cache->put('report', $this->big, 600);

    $this->slack->fileOutage(1, 'disconnect');
    expect(coldSlackStore()->get('report'))->toBe($this->big);

    Event::assertDispatched(SlackRequestRetrying::class, fn ($e) => $e->method === 'files.upload' && $e->reason === 'http_503');
    Event::assertDispatched(SlackRequestRetrying::class, fn ($e) => $e->method === 'files.download' && $e->reason === 'connection_failed');
});

it('compresses and encrypts before uploading', function () {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    config()->set('cache.stores.slack.encrypt', true);
    config()->set('cache.stores.slack.compress', true);
    $value = bin2hex(random_bytes(20_000)); // Compresses to about half: still too large for a message.

    Cache::store('slack')->put('report', $value, 600);

    $bytes = reset($this->slack->files)['bytes'];
    expect($bytes)->toStartWith('SC1E:')->not->toContain(substr($value, 0, 32))
        ->and(coldSlackStore()->get('report'))->toBe($value);
});

it('lets the fake assertions read values stored as files', function () {
    $slack = SlackCache::fake();
    config()->set('cache.stores.slack.files', true);
    app('cache')->forgetDriver('slack');

    Cache::store('slack')->put('report', $this->big, 600);

    $slack->assertStored('report', $this->big);
});

it('ignores a malformed file pointer', function () {
    expect(Codec::decode('slackcache:v1 '.base64_encode('k').' - @nope'))->toBeNull()
        ->and(Codec::decode('slackcache:v1 '.base64_encode('k').' - @F123:'.str_repeat('a', 64)))->toBe(['k', '', null, ['F123', str_repeat('a', 64)]]);
});
