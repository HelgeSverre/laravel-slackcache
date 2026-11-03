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
        $reaped = $this->slackStore()->keyspace()->reap(max(1, (int) $this->option('limit')));

        $this->components->info(sprintf('Reaped %d expired %s.', $reaped, $reaped === 1 ? 'key' : 'keys'));

        return self::SUCCESS;
    }
}
