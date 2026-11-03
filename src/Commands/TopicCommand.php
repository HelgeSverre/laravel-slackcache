<?php

namespace HelgeSverre\SlackCache\Commands;

use Illuminate\Console\Command;

class TopicCommand extends Command
{
    use ResolvesSlackStore;

    protected $signature = 'slackcache:topic
        {--store=slack : The cache store that uses the slack driver}
        {--keep-notice : Leave Slack\'s "set the channel topic" message in the channel}';

    protected $description = 'Set each cache channel\'s topic to a count of what it holds';

    public function handle(): int
    {
        $keyspace = $this->slackStore()->keyspace();
        $changed = $keyspace->updateTopics(keepNotice: (bool) $this->option('keep-notice'));

        foreach ($keyspace->channels() as $channel) {
            $this->components->twoColumnDetail("#{$channel->name}", $channel->topic());
        }

        $this->components->info($changed === 0
            ? 'Every topic was already current.'
            : sprintf('Updated %d %s.', $changed, $changed === 1 ? 'topic' : 'topics'));

        return self::SUCCESS;
    }
}
