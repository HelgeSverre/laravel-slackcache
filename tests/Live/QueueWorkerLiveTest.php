<?php

/**
 * Write-behind and background sync with a real queue worker: a separate PHP process (tests/Live/worker.php)
 * working a database queue, sharing a database replica with this process. Skipped unless SLACKCACHE_LIVE_TOKEN is set.
 */

use HelgeSverre\SlackCache\Codec;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/support.php';

beforeEach(function () {
    if (! getenv('SLACKCACHE_LIVE_TOKEN')) {
        $this->markTestSkipped('Set SLACKCACHE_LIVE_TOKEN to run against a real workspace.');
    }

    $this->database = tempnam(sys_get_temp_dir(), 'slackcache-live-').'.sqlite';
    touch($this->database);

    foreach (slackcacheLiveWorkerConfig($this->database) as $key => $value) {
        config()->set($key, $value);
    }

    Schema::create('cache', function (Blueprint $table) {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });
    Schema::create('cache_locks', function (Blueprint $table) {
        $table->string('key')->primary();
        $table->string('owner');
        $table->integer('expiration');
    });
    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    $this->work = function (): string {
        $result = Process::timeout(300)->env(['SLACKCACHE_LIVE_TOKEN' => getenv('SLACKCACHE_LIVE_TOKEN')])
            ->run([PHP_BINARY, __DIR__.'/worker.php', $this->database]);

        expect($result->exitCode())->toBe(0, $result->errorOutput().$result->output());

        return $result->output();
    };

    $this->slackKeys = function (): array {
        $keys = [];

        foreach (Cache::store('slack')->getStore()->keyspace()->channels()[0]->messages() as [, $text]) {
            $keys[] = Codec::decode($text)[0] ?? null;
        }

        sort($keys);

        return $keys;
    };
});

afterEach(function () {
    if (isset($this->database)) {
        @unlink($this->database);
    }
});

it('writes behind and re-syncs through a real queue worker in another process', function () {
    $cache = Cache::store('slack');
    $cache->getStore()->keyspace()->load();
    $cache->getStore()->keyspace()->flush();

    // Write-behind: the app sees the value at once; Slack does not, until a worker runs the job.
    $started = hrtime(true);
    $cache->put('worker:greeting', 'hello from the app', 600);
    $cache->put('worker:report', $big = random_bytes(30_000), 600);
    $milliseconds = intdiv(hrtime(true) - $started, 1_000_000);

    expect($cache->get('worker:greeting'))->toBe('hello from the app')
        ->and($milliseconds)->toBeLessThan(1000)
        ->and(DB::table('jobs')->count())->toBe(2)
        ->and(($this->slackKeys)())->toBe([]);

    $output = ($this->work)();

    expect($output)->toContain('WriteToSlack')
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(($this->slackKeys)())->toBe(['worker:greeting', 'worker:report'])
        ->and($cache->getStore()->keyspace()->status()['pending'])->toBe(0);

    // Another server writes straight to the channel. Once the replica is stale, a read queues a re-sync
    // and keeps serving the replica; the worker's reload brings the new key in.
    $channel = $cache->getStore()->keyspace()->channels()[0];
    $channel->post(...Codec::encode('worker:elsewhere', serialize('from another server'), null));
    sleep(3);

    expect($cache->get('worker:elsewhere'))->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(1);

    expect(($this->work)())->toContain('ReloadKeyspace')
        ->and($cache->get('worker:elsewhere'))->toBe('from another server')
        ->and($cache->get('worker:report'))->toBe($big);

    // Deletes are queued too.
    $cache->forget('worker:greeting');
    expect($cache->get('worker:greeting'))->toBeNull()
        ->and(($this->slackKeys)())->toContain('worker:greeting');

    ($this->work)();

    expect(($this->slackKeys)())->toBe(['worker:elsewhere', 'worker:report']);

    $cache->getStore()->keyspace()->flush();
    expect(($this->slackKeys)())->toBe([]);
})->group('live');
