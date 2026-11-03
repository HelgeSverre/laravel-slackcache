<?php

namespace HelgeSverre\SlackCache\Commands;

use Illuminate\Console\Command;

class ReapCommand extends Command
{
    use ResolvesSlackStore;

    protected $signature = 'slackcache:reap
        {--store=slack : The cache store that uses the slack driver}
        {--limit=20 : The most messages to delete in one run (Redis checks 20 keys per expiry cycle, so do we)}';

    protected $description = 'Delete the Slack messages of expired keys';

    public function handle(): int
    {
        $store = $this->slackStore();
        $limit = max(1, (int) $this->option('limit'));
        $reaped = $store->keyspace()->reap($limit);

        // Expired locks that were never released, in the lock channel.
        if ($store->lockStore() !== null && $reaped < $limit) {
            $reaped += $store->lockStore()->keyspace()->reap($limit - $reaped);
        }

        $this->components->info(sprintf('Reaped %d expired %s.', $reaped, $reaped === 1 ? 'key' : 'keys'));

        return self::SUCCESS;
    }
}
