<?php

namespace HelgeSverre\SlackCache\Health;

use HelgeSverre\SlackCache\SlackStore;
use Illuminate\Support\Facades\Cache;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Throwable;

/**
 * A check for spatie/laravel-health: the token works, the channels resolve, and queued writes are not piling up.
 *
 *     Health::checks([
 *         SlackCacheCheck::new()->store('slack')->warnWhenPendingWritesAbove(100),
 *     ]);
 */
class SlackCacheCheck extends Check
{
    protected string $store = 'slack';

    protected int $warnPendingAbove = 100;

    protected int $failPendingAbove = 1000;

    protected int $warnSlowerThanMs = 2000;

    public function store(string $store): self
    {
        $this->store = $store;

        return $this;
    }

    public function warnWhenPendingWritesAbove(int $count): self
    {
        $this->warnPendingAbove = $count;

        return $this;
    }

    public function failWhenPendingWritesAbove(int $count): self
    {
        $this->failPendingAbove = $count;

        return $this;
    }

    public function warnWhenSlowerThan(int $milliseconds): self
    {
        $this->warnSlowerThanMs = $milliseconds;

        return $this;
    }

    public function run(): Result
    {
        $result = Result::make();

        try {
            $store = Cache::store($this->store)->getStore();
        } catch (Throwable $e) {
            return $result->failed("Cache store [{$this->store}] is not available: {$e->getMessage()}")->shortSummary('Misconfigured');
        }

        if (! $store instanceof SlackStore) {
            return $result->failed("Cache store [{$this->store}] does not use the slack driver.")->shortSummary('Not Slack');
        }

        $keyspace = $store->keyspace();
        $api = $keyspace->api();
        $started = hrtime(true);

        try {
            $auth = $api->call('auth.test');
            $channels = $keyspace->channels();
        } catch (Throwable $e) {
            return $result->failed("Slack is not reachable with this token: {$e->getMessage()}")->shortSummary('Unreachable');
        }

        $milliseconds = intdiv(hrtime(true) - $started, 1_000_000);
        $status = $keyspace->status();
        $names = implode(', ', array_map(fn ($channel) => "#{$channel->name}", $channels));

        $result->meta([
            'workspace' => $auth['team'] ?? null,
            'token' => $api->tokenType(),
            'channels' => $names,
            'latency_ms' => $milliseconds,
            'pending_writes' => $status['pending'],
            'replica_synced' => $status['synced'],
            'replica_generation' => $status['generation'],
        ]);

        $summary = $names.($status['pending'] > 0 ? " · {$status['pending']} pending" : '');
        $result->shortSummary($summary);

        if ($status['pending'] > $this->failPendingAbove) {
            return $result->failed("{$status['pending']} writes are queued for Slack. Is a queue worker running?");
        }

        if ($status['pending'] > $this->warnPendingAbove) {
            return $result->warning("{$status['pending']} writes are queued for Slack.");
        }

        if ($milliseconds > $this->warnSlowerThanMs) {
            return $result->warning("Slack took {$milliseconds} ms to answer.");
        }

        return $result->ok();
    }
}
