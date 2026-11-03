---
name: slackcache-development
description: Configure, test and troubleshoot the SlackCache Laravel cache driver (helgesverre/laravel-slackcache), which stores cache keys as messages in a Slack channel. Use when adding or changing a `slack` cache store, choosing its options, writing tests for code that caches through it, or debugging Slack API errors, rate limits and stale reads.
---

# SlackCache development

## When to use this skill

- Adding a `slack` store to `config/cache.php` or changing its options.
- Writing tests for code that uses the store.
- Investigating errors: `SlackApiException`, `ValueTooLargeException`, rate limits, keys that come back after `forget()`.

## Configure a store

```php
// config/cache.php
'slack' => [
    'driver' => 'slack',
    'token' => env('SLACKCACHE_TOKEN'),   // xoxb-… bot token
    'channel' => env('SLACKCACHE_CHANNEL', 'slackcache'),
    'replica' => 'redis',                 // shared read copy; required for async, sync=background, and several servers
],
```

Choose options by need:

| Need | Option |
| --- | --- |
| Writes must not block the request | `'async' => true` and a queue worker |
| Reads must never wait for a re-sync | `'sync' => 'background'` |
| Values over ~11.9 KB | `'compress' => true`, then `'files' => true` (adds `files:read`, `files:write`) |
| Secrets in values | `'encrypt' => true` (uses `APP_KEY`) |
| More than ~1 write per second | `'shards' => N` |
| Slack outages must not break the app | a `failover` store: `['slack', 'file']`, with `'retries' => 0` and a short `'timeout'` on the slack store |

The Slack app needs `chat:write`, `channels:read`, `channels:history`, `channels:join`, and `channels:manage` to create the channel. Print a manifest with `php artisan slackcache:manifest`.

## Schedule maintenance

```php
// routes/console.php
Schedule::command('slackcache:reap')->everyMinute();         // delete expired messages
Schedule::command('slackcache:topic')->everyTenMinutes();    // optional: key counts in the channel topic
```

## Monitor

- spatie/laravel-health: `Health::checks([SlackCacheCheck::new()->store('slack')])` (`HelgeSverre\SlackCache\Health\SlackCacheCheck`). Fails on a bad token or a backlog of queued writes.
- Laravel Pulse: add `HelgeSverre\SlackCache\Pulse\SlackCacheRecorder::class => []` to `pulse.recorders` and `<livewire:slackcache cols="6" />` to the dashboard.
- Events in `HelgeSverre\SlackCache\Events`: `SlackApiCalled`, `SlackRateLimited`, `SlackRequestRetrying`, `KeyspaceReloaded`, `HistoryReplyFailed`.

## Test code that uses the store

`SlackCache::fake()` swaps Slack for an in-memory workspace and rebuilds every slack store with a fresh replica. Assertions read keys without the cache prefix and compare unserialized values:

```php
$slack = SlackCache::fake();

Cache::store('slack')->put('greeting', 'hello', 60);

$slack->assertStored('greeting', 'hello');
$slack->assertNotStored('farewell');
$slack->assertStoredCount(1);

$slack->rateLimit(times: 2);   // simulate HTTP 429s
$slack->fail('channel_not_found');
```

## Troubleshoot

1. Run `php artisan slackcache:doctor`. It checks the token, scopes, channels, posting, history and the replica, and names what is missing.
2. `ValueTooLargeException`: turn on `compress`, then `files`.
3. A forgotten key comes back: servers use separate replicas. Give them a shared `redis` or `database` replica.
4. Slow requests: every write waits for Slack. Use `async`.
