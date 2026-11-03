## SlackCache (helgesverre/laravel-slackcache)

A Laravel cache store persisted to a Slack channel: one message per key, reads served from a replica (another cache store). Writes take about a second each; reads are as fast as the replica.

### Rules

- Configure it as a store in `config/cache.php` with `'driver' => 'slack'`. Always set `replica` to a shared store (`redis`, `database` or `file`), never to a store that itself uses the slack driver.
- One key and value must fit in one Slack message (about 11.9 KB of value). Set `compress` for larger values; do not split values by hand.
- Use the normal `Cache` API (`Cache::store('slack')->put()`, `remember()`, `tags()`, `lock()`, `flexible()`). Do not call the Slack API directly to read or write cache keys.
- Expired messages stay in the channel until reaped: schedule `slackcache:reap`. `slackcache:topic` and `slackcache:decorate` are optional extras for the scheduler.
- For slow writes, use `'async' => true` (write-behind through the queue) rather than wrapping calls in jobs yourself. It needs a queue worker and a shared replica.
- Diagnose setup problems with `php artisan slackcache:doctor` before changing code.

### Testing

In tests, never hit Slack. Fake it, then assert on what was stored:

@verbatim
<code-snippet name="Faking Slack in a test" lang="php">
use HelgeSverre\SlackCache\SlackCache;
use Illuminate\Support\Facades\Cache;

it('caches the report', function () {
    $slack = SlackCache::fake();

    $this->get('/reports/weekly')->assertOk();

    $slack->assertStored('reports:weekly');
    $slack->assertStored('reports:weekly', fn ($report) => $report['total'] > 0);
    $slack->assertNotStored('reports:draft');
});
</code-snippet>
@endverbatim
