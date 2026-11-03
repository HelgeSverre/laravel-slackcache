<?php

namespace HelgeSverre\SlackCache\Commands;

use HelgeSverre\SlackCache\SlackStore;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Support\Facades\Cache;
use Throwable;

class DoctorCommand extends Command
{
    protected $signature = 'slackcache:doctor {--store=slack : The cache store that uses the slack driver}';

    protected $description = 'Check the Slack cache store\'s token, scopes, channels and settings';

    /** Scopes every setup needs. channels:manage is only needed to create channels. */
    public const array REQUIRED_SCOPES = ['chat:write', 'channels:read', 'channels:history', 'channels:join'];

    protected int $failures = 0;

    public function handle(): int
    {
        $this->failures = 0; // The same instance can run twice in one process (Artisan::call).

        $option = $this->option('store');
        $name = is_string($option) && $option !== '' ? $option : 'slack';
        $config = (array) config("cache.stores.{$name}", []);

        $store = $this->check('Store', function () use ($name) {
            $store = Cache::store($name)->getStore();

            return $store instanceof SlackStore
                ? [$store, "[{$name}] uses the slack driver"]
                : throw new \RuntimeException("[{$name}] does not use the slack driver");
        });

        if (! $store instanceof SlackStore) {
            return $this->finish();
        }

        $keyspace = $store->keyspace();
        $api = $keyspace->api();

        $auth = $this->check('Token', function () use ($api) {
            $auth = $api->call('auth.test');

            return [$auth, sprintf('%s token · %s · %s', $api->tokenType(), $auth['team'] ?? '?', $auth['user'] ?? '?')];
        });

        if ($auth === null) {
            return $this->finish();
        }

        $scopes = $api->scopes();

        if ($scopes === null || ! in_array($api->tokenType(), ['bot', 'user'], true)) {
            // Browser session tokens report Slack's own client scopes, which say nothing about this app.
            $this->warned('Scopes', 'not checked for '.$api->tokenType().' tokens');
        } else {
            $missing = array_values(array_diff(self::REQUIRED_SCOPES, $scopes));

            $missing === []
                ? $this->passed('Scopes', implode(', ', $scopes))
                : $this->failed('Scopes', 'missing '.implode(', ', $missing));

            if (($config['reactions'] ?? false) && ! in_array('reactions:write', $scopes, true)) {
                $this->failed('reactions:write', 'missing; the reactions option needs it');
            }

            if (! in_array('channels:manage', $scopes, true)) {
                $this->warned('channels:manage', 'missing; fine while the channels exist, needed to create them');
            }
        }

        $channels = $this->check('Channels', function () use ($keyspace) {
            $channels = $keyspace->channels();

            return [$channels, implode(', ', array_map(fn ($c) => "#{$c->name} ({$c->id})", $channels))];
        });

        foreach ($channels ?? [] as $channel) {
            $this->check("Post and delete #{$channel->name}", function () use ($channel) {
                $channel->probe('slackcache doctor: checking that I can post and delete here. This message deletes itself.');

                return [true, 'ok'];
            });

            $this->check("History #{$channel->name}", function () use ($channel) {
                $count = iterator_count($channel->messages());

                return [true, "{$count} ".($count === 1 ? 'message' : 'messages').' from this token'];
            });
        }

        $replica = $config['replica'] ?? null;

        if ($replica === null || $replica === 'array') {
            $this->warned('Replica', 'per-process: every PHP process reads the whole channel once. Use file, redis or database.');
        } else {
            $locks = $keyspace->replica()->getStore() instanceof LockProvider;
            $locks
                ? $this->passed('Replica', "[{$replica}], with locks")
                : $this->warned('Replica', "[{$replica}] has no locks: add, increment and reloads are not atomic across processes");
        }

        if (($config['async'] ?? false) || ($config['sync'] ?? 'inline') === 'background') {
            $driver = is_string($replica) ? config("cache.stores.{$replica}.driver") : null;

            in_array($driver, ['array', 'file', null], true)
                ? $this->warned('Queue', "[{$driver}] replica: queue workers must share it with the app. Fine on one machine with file; not with array.")
                : $this->passed('Queue', "jobs share the [{$replica}] replica with the app");
        }

        if ($config['encrypt'] ?? false) {
            $this->check('Encryption', function () {
                $encrypter = app(StringEncrypter::class);

                return $encrypter->decryptString($encrypter->encryptString('slackcache')) === 'slackcache'
                    ? [true, 'APP_KEY encrypts and decrypts']
                    : throw new \RuntimeException('round trip failed');
            });
        }

        if ($config['compress'] ?? false) {
            function_exists('gzdeflate') ? $this->passed('Compression', 'zlib available') : $this->failed('Compression', 'the zlib extension is missing');
        }

        return $this->finish();
    }

    /**
     * Run a check. The callback returns [result, detail] or throws.
     *
     * @template T
     *
     * @param  callable(): array{0: T, 1: string}  $callback
     * @return T|null
     */
    protected function check(string $label, callable $callback): mixed
    {
        try {
            [$result, $detail] = $callback();
        } catch (Throwable $e) {
            $this->failed($label, $e->getMessage());

            return null;
        }

        $this->passed($label, $detail);

        return $result;
    }

    protected function passed(string $label, string $detail): void
    {
        $this->components->twoColumnDetail($label, "<fg=green>✓</> {$detail}");
    }

    protected function warned(string $label, string $detail): void
    {
        $this->components->twoColumnDetail($label, "<fg=yellow>!</> {$detail}");
    }

    protected function failed(string $label, string $detail): void
    {
        $this->failures++;
        $this->components->twoColumnDetail($label, "<fg=red>✗</> {$detail}");
    }

    protected function finish(): int
    {
        $this->newLine();

        if ($this->failures === 0) {
            $this->components->info('All checks passed.');

            return self::SUCCESS;
        }

        $this->components->error(sprintf('%d %s failed.', $this->failures, $this->failures === 1 ? 'check' : 'checks'));

        return self::FAILURE;
    }
}
