<?php

namespace HelgeSverre\SlackCache\Commands;

use Illuminate\Console\Command;

class InfoCommand extends Command
{
    use ResolvesSlackStore;

    protected $signature = 'slackcache:info {--store=slack : The cache store that uses the slack driver}';

    protected $description = 'Show the Slack cache store\'s channels and settings, in the style of Redis INFO';

    public function handle(): int
    {
        $this->components->twoColumnDetail('<fg=green;options=bold>slackcache</>');

        foreach ($this->slackStore()->keyspace()->info() as $key => $value) {
            $this->components->twoColumnDetail($key, $value === null ? 'null' : (string) $value);
        }

        return self::SUCCESS;
    }
}
