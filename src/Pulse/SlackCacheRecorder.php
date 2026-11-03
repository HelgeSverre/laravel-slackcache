<?php

namespace HelgeSverre\SlackCache\Pulse;

use HelgeSverre\SlackCache\Events\KeyspaceReloaded;
use HelgeSverre\SlackCache\Events\SlackApiCalled;
use HelgeSverre\SlackCache\Events\SlackRateLimited;
use HelgeSverre\SlackCache\Events\SlackRequestRetrying;
use Laravel\Pulse\Pulse;

/**
 * Records Slack API calls, rate limits, retries and reloads for the SlackCache Pulse card.
 *
 *     // config/pulse.php
 *     'recorders' => [
 *         \HelgeSverre\SlackCache\Pulse\SlackCacheRecorder::class => ['enabled' => env('PULSE_SLACKCACHE_ENABLED', true)],
 *     ],
 */
class SlackCacheRecorder
{
    /** @var list<class-string> */
    public array $listen = [
        SlackApiCalled::class,
        SlackRateLimited::class,
        SlackRequestRetrying::class,
        KeyspaceReloaded::class,
    ];

    public function __construct(protected Pulse $pulse) {}

    public function record(SlackApiCalled|SlackRateLimited|SlackRequestRetrying|KeyspaceReloaded $event): void
    {
        match (true) {
            $event instanceof SlackApiCalled => $this->called($event),
            $event instanceof SlackRateLimited => $this->pulse->record('slackcache_rate_limited', $event->method, $event->retryAfter)->count()->sum(),
            $event instanceof SlackRequestRetrying => $this->pulse->record('slackcache_retry', $event->method, $event->delay)->count()->sum(),
            $event instanceof KeyspaceReloaded => $this->pulse->record('slackcache_reload', implode(', ', $event->channels), $event->milliseconds)->count()->avg()->max(),
        };
    }

    protected function called(SlackApiCalled $event): void
    {
        $this->pulse->record('slackcache_call', $event->method, $event->milliseconds)->count()->avg()->max();

        if ($event->error !== null) {
            $this->pulse->record('slackcache_error', $event->method.': '.$event->error)->count();
        }
    }
}
