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

        $store = $this->slackStore();

        foreach ($store->keyspace()->info() as $key => $value) {
            $this->components->twoColumnDetail($key, $value === null ? 'null' : (string) $value);
        }

        $locks = $store->lockStore()?->keyspace()->info()['slack_channels'] ?? null;
        $this->components->twoColumnDetail('lock_channel', $locks === null ? 'shared' : (string) $locks);

        return self::SUCCESS;
    }
}
