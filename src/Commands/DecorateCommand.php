<?php

namespace HelgeSverre\SlackCache\Commands;

use Illuminate\Console\Command;

class DecorateCommand extends Command
{
    use ResolvesSlackStore;

    protected $signature = 'slackcache:decorate
        {--store=slack : The cache store that uses the slack driver}
        {--limit=20 : The most reactions to add or remove in one run}';

    protected $description = 'React to cache messages: 🔥 hot, ⌛ expiring soon, 💀 expired';

    public function handle(): int
    {
        $store = $this->slackStore();

        if (! $store->keyspace()->reactionsEnabled()) {
            $this->components->error('Turn on the reactions option for this store first.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $result = $store->keyspace()->decorate($limit);
        $spent = $result['added'] + $result['removed'];

        if ($store->lockStore() !== null && $spent < $limit) {
            $locks = $store->lockStore()->keyspace()->decorate($limit - $spent);
            $result['added'] += $locks['added'];
            $result['removed'] += $locks['removed'];
        }

        $this->components->info(sprintf(
            'Added %d %s, removed %d.',
            $result['added'],
            $result['added'] === 1 ? 'reaction' : 'reactions',
            $result['removed'],
        ));

        return self::SUCCESS;
    }
}
