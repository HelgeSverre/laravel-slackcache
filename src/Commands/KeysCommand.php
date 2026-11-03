<?php

namespace HelgeSverre\SlackCache\Commands;

use Illuminate\Console\Command;

class KeysCommand extends Command
{
    use ResolvesSlackStore;

    protected $signature = 'slackcache:keys
        {pattern? : A glob pattern (default *), as for Redis KEYS: *, ? and [abc]}
        {--store=slack : The cache store that uses the slack driver}';

    protected $description = 'List the live keys that match a pattern, read from the Slack channel';

    public function handle(): int
    {
        $this->components->warn('KEYS reads the whole channel history. Redis advises against KEYS in production. So do we.');

        $store = $this->slackStore();
        $prefix = $store->getPrefix();
        $argument = $this->argument('pattern');
        $pattern = is_string($argument) && $argument !== '' ? $argument : '*';
        $count = 0;

        foreach ($store->keyspace()->scan() as $entry) {
            if (! str_starts_with($entry->key, $prefix)) {
                continue;
            }

            $key = substr($entry->key, strlen($prefix));

            if (fnmatch($pattern, $key, FNM_NOESCAPE)) {
                $this->line($key);
                $count++;
            }
        }

        $this->components->info(sprintf('%d %s.', $count, $count === 1 ? 'key' : 'keys'));

        return self::SUCCESS;
    }
}
