# Changelog

All notable changes to `laravel-slackcache` will be documented in this file.

## v0.4.0 - 2026-11-03

### Added

- `history` option: overwrites edit the key's message in place and post the previous value as a reply in its thread. Deleting a key deletes its thread first, so Slack leaves no placeholder.
- `slackcache:keys {pattern}`: list live keys matching a glob pattern, like Redis `KEYS`, read from the channel.
- `FakeSlack` supports `chat.update`, thread replies (`replies()`), `conversations.replies` and Slack's deleted-parent placeholders.
- Live smoke test for version history.

## v0.3.0 - 2026-11-03

### Added

- Cache tags: `Cache::store('slack')->tags([...])`. Tags appear in each message's preview. Flushing tags deletes the messages of keys stored under exactly those tags.
- `section($name)`, the Laravel 4.0 name for a single tag.
- `lock_channel` option: locks in a channel of their own. `flushLocks()` and `hasSeparateLockStore()` (Laravel 13's `CanFlushLocks`, polyfilled on Laravel 12), so `php artisan cache:clear --locks` works on Laravel 13.
- Live smoke test for tags and the lock channel.

## v0.2.0 - 2026-11-03

### Added

- `slack` session driver (`SESSION_DRIVER=slack`), using the cache store named in `session.store` (default `slack`).
- SlackCache section in `php artisan about`, read from config. Slack is not called.
- Documentation and tests for `Cache::flexible()`, `Cache::memo()`, rate limiting, `cache:clear`/`cache:forget` and the `failover` driver.

## v0.1.0 - 2026-11-03

- `slack` cache driver: one Slack message per key, read from a local replica, wire-compatible with the original Python SlackCache.
- Full cache contract, including `add`, `touch` and atomic locks.
- Free-plan retention as an eviction policy (`retention_days`), Redis Cluster hash-slot sharding (`shards`).
- `slackcache:load`, `slackcache:reap`, `slackcache:info` and `slackcache:manifest` commands.
- `FakeSlack` testing helper.
