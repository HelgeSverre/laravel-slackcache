# Changelog

All notable changes to `laravel-slackcache` will be documented in this file.

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
