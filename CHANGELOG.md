# Changelog

All notable changes to `laravel-slackcache` will be documented in this file.

## v0.8.0 - 2026-11-03

### Added

- `reactions` option and `slackcache:decorate`: reactions for hot (`:fire:`), expiring (`:hourglass:`) and expired (`:skull:`) keys, kept in sync with each key's state within a `--limit` call budget. Read counts live in the replica, per message, for a day. Only the driver's own reactions are touched.
- `reactions:write` in the app manifest; `slackcache:doctor` checks it when `reactions` is on.
- `FakeSlack` supports `reactions.add`, `reactions.remove` and `reactAs()`.

### Fixed

- `slackcache:doctor` reset its failure count between runs in the same process.

## v0.7.0 - 2026-11-03

### Added

- `emoji` option: each message's preview starts with Slack emoji for the kind of key: `:hourglass_flowing_sand:` (TTL), `:infinity:` (no TTL), `:lock:` (lock), `:label:` (tagged), `:closed_lock_with_key:` (encrypted), `:compression:` (compressed). No extra API calls; the message text is unchanged.

## v0.6.0 - 2026-11-03

### Added

- `sync => 'background'`: a stale replica keeps serving reads while a queued `ReloadKeyspace` job re-reads the channel. One job at a time; a cold replica still loads inline.
- `async => true` (write-behind): writes and deletes update the replica at once and reach Slack through queued `WriteToSlack` and `DeleteFromSlack` jobs. Sequence numbers make stale, repeated or out-of-order jobs skip themselves, and a flush drops writes queued before it.
- `queue_connection` and `queue` options for those jobs.
- Queued writes and deletes stay in the replica for up to a day while their jobs wait, and re-syncs carry them over, including keys Slack has not seen yet.
- Write order is decided by wall-clock sequence numbers, comparable across processes and servers. Re-syncs keep each key's sequence number, so a late retry of an older write stays stale.
- Jobs hold the key's lock only around replica bookkeeping, not during Slack calls, and reconcile writes or deletes that landed while they posted. A retried job does not post twice.
- `async` and `sync => 'background'` require a replica; `slackcache:doctor` warns when it cannot be shared with queue workers.
- A background re-sync that cannot be queued no longer fails the read.

### Changed

- Requires `illuminate/bus` and `illuminate/queue` (part of every Laravel app).
- The per-key replica lock is reentrant within a process.
- With `sync => 'background'`, replica entries live at least an hour, so a slow queue serves older data instead of misses.

## v0.5.0 - 2026-11-03

### Added

- `encrypt` option: values encrypted with Laravel's encrypter (`APP_KEY`). Previews and history replies show `(encrypted)`. Undecryptable values are cache misses.
- `compress` option: values gzip-compressed when that makes them smaller, so larger values fit in one message. Applied before encryption.
- `slackcache:doctor`: checks the token, scopes, channels, posting, history, replica, encryption and compression.
- Retries for Slack server errors and dropped connections, with exponential backoff (1, 2, 4 … seconds, at most 30), within the existing `retries` limit.
- Events: `SlackRateLimited`, `SlackRequestRetrying`, `KeyspaceReloaded`, `HistoryReplyFailed`.
- `FakeSlack::serverError()` and `FakeSlack::disconnect()`. FakeSlack now sends `x-oauth-scopes` headers like Slack does.

### Changed

- A failed history reply dispatches `HistoryReplyFailed` instead of calling `report()`.

## v0.4.1 - 2026-11-03

### Fixed

- `slackcache:keys` reads its pattern argument without a cast PHPStan rejects in CI.

## v0.4.0 - 2026-11-03

### Added

- `history` option: overwrites edit the key's message in place and post the previous value as a reply in its thread. Deleting a key deletes its thread first, so Slack leaves no placeholder.
- `slackcache:keys {pattern}`: list live keys matching a glob pattern, like Redis `KEYS`, read from the channel.
- `slackcache:reap` also reaps expired locks in `lock_channel`. `slackcache:load` and `slackcache:info` include the lock channel.
- `TextMismatchException`, thrown when Slack stores different text than was sent.
- `FakeSlack` supports `chat.update`, thread replies (`replies()`), `conversations.replies` and Slack's deleted-parent placeholders.
- Live smoke test for version history.

### Notes

- With `history` on, a key whose message is past half of `retention_days` is reposted on its next write, so rewritten keys outlive the free plan. Its thread starts over.

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
