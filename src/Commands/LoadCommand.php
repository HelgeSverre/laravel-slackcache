<?php

namespace HelgeSverre\SlackCache\Commands;

use Illuminate\Console\Command;

class LoadCommand extends Command
{
    use ResolvesSlackStore;

    protected $signature = 'slackcache:load {--store=slack : The cache store that uses the slack driver}';

    protected $description = 'Rebuild the local replica from the Slack channel history';

    public function handle(): int
    {
        $started = microtime(true);
        $keys = $this->slackStore()->keyspace()->load();

        $this->components->info(sprintf('Loaded %d %s from Slack in %.2f s.', $keys, $keys === 1 ? 'key' : 'keys', microtime(true) - $started));

        return self::SUCCESS;
    }
}
