<?php

/**
 * Config shared by the live queue-worker test and the worker process it starts: one SQLite file holds the
 * replica, its locks and the job queue, so both processes see the same state, as an app server and a worker would.
 *
 * @return array<string, mixed>
 */
function slackcacheLiveWorkerConfig(string $database): array
{
    $cookie = getenv('SLACKCACHE_LIVE_COOKIE');

    return [
        'database.default' => 'live',
        'database.connections.live' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 10_000],
        'queue.default' => 'live',
        'queue.connections.live' => ['driver' => 'database', 'connection' => 'live', 'table' => 'jobs', 'queue' => 'slackcache', 'retry_after' => 300],
        'queue.failed.driver' => 'null',
        'cache.prefix' => '',
        'cache.stores.replica' => ['driver' => 'database', 'connection' => 'live', 'table' => 'cache', 'lock_connection' => 'live', 'lock_table' => 'cache_locks'],
        'cache.stores.slack' => [
            'driver' => 'slack',
            'token' => getenv('SLACKCACHE_LIVE_TOKEN'),
            'headers' => $cookie ? ['Cookie' => 'd='.rawurlencode($cookie)] : [],
            'base_url' => getenv('SLACKCACHE_LIVE_BASE_URL') ?: 'https://slack.com/api/',
            'channel' => getenv('SLACKCACHE_LIVE_CHANNEL') ?: 'slackcache-smoke',
            'replica' => 'replica',
            'sync_interval' => 2,
            'async' => true,
            'sync' => 'background',
            'queue_connection' => 'live',
            'queue' => 'slackcache',
            'files' => true,
            'timeout' => 60,
        ],
    ];
}
