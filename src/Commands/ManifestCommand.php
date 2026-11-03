<?php

namespace HelgeSverre\SlackCache\Commands;

use Illuminate\Console\Command;

class ManifestCommand extends Command
{
    protected $signature = 'slackcache:manifest';

    protected $description = 'Print a Slack app manifest for the cache bot';

    public function handle(): int
    {
        foreach (file(__DIR__.'/../../resources/slack-app-manifest.yml', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
