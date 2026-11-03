<?php

namespace HelgeSverre\SlackCache\Pulse;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Laravel\Pulse\Livewire\Concerns\HasPeriod;
use Laravel\Pulse\Livewire\Concerns\RemembersQueries;
use Livewire\Attributes\Lazy;

/**
 * A Pulse card for the SlackCache recorder: API calls by method, rate limits, retries, errors and reloads.
 *
 *     <livewire:slackcache cols="6" />
 */
#[Lazy]
class SlackCacheCard extends Card
{
    use HasPeriod, RemembersQueries;

    public function render(): Renderable
    {
        [$calls, $time, $runAt] = $this->remember(fn () => $this->aggregate('slackcache_call', ['count', 'avg', 'max'], 'count'), 'calls');

        [$totals] = $this->remember(fn () => [
            'rateLimited' => $this->aggregate('slackcache_rate_limited', ['count', 'sum'], 'count'),
            'retries' => $this->aggregate('slackcache_retry', ['count'], 'count'),
            'errors' => $this->aggregate('slackcache_error', ['count'], 'count'),
            'reloads' => $this->aggregate('slackcache_reload', ['count', 'avg', 'max'], 'count'),
        ], 'totals');

        return View::make('slackcache::pulse.card', [
            'calls' => $calls,
            'time' => $time,
            'runAt' => $runAt,
            'rateLimited' => $totals['rateLimited'],
            'retries' => $totals['retries'],
            'failures' => $totals['errors'],
            'reloads' => $totals['reloads'],
        ]);
    }
}
