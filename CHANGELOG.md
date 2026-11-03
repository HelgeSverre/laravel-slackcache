# Changelog

All notable changes to `laravel-slackcache` will be documented in this file.

## v0.1.0 - 2026-11-03

- `slack` cache driver: one Slack message per key, read from a local replica, wire-compatible with the original Python SlackCache.
- Full cache contract, including `add`, `touch` and atomic locks.
- Free-plan retention as an eviction policy (`retention_days`), Redis Cluster hash-slot sharding (`shards`).
- `slackcache:load`, `slackcache:reap`, `slackcache:info` and `slackcache:manifest` commands.
- `FakeSlack` testing helper.
