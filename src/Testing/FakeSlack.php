<?php

namespace HelgeSverre\SlackCache\Testing;

use GuzzleHttp\Promise\PromiseInterface;
use HelgeSverre\SlackCache\Entry;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;

/**
 * An in-memory Slack workspace, served through Laravel's HTTP fake.
 *
 * Implements the Web API methods SlackCache calls, with the behaviour that matters:
 * newest-first paginated history, `message_not_found` on double deletes, text truncation
 * when a message has blocks, and optional HTTP 429s.
 *
 *     $slack = FakeSlack::install();
 *     Cache::store('slack')->put('hello', 'world', 60);
 *     $slack->messages('slackcache'); // one message
 */
class FakeSlack
{
    /** @var array<string, array{name: string, members: list<string>, messages: array<string, array<string, mixed>>, replies?: array<string, array<string, array<string, mixed>>>}> */
    public array $channels = [];

    /** @var list<array{method: string, params: array<string, mixed>}> */
    public array $calls = [];

    protected int $counter = 0;

    protected int $rateLimits = 0;

    protected int $retryAfter = 1;

    /** @var list<string> */
    protected array $failures = [];

    /** @var array<string, list<callable(array<string, mixed>): void>> */
    protected array $hooks = [];

    /** @var list<string> */
    protected array $private = [];

    public function __construct(
        public string $botId = 'B0SLACKCACHE',
        public string $userId = 'U0SLACKCACHE',
        public bool $isBot = true,
        public int $textLimitWithBlocks = 16_000,
        public int $historyPageSize = 200,
    ) {}

    public static function install(?Factory $http = null, mixed ...$options): self
    {
        $fake = new self(...$options);
        $http ??= app(Factory::class);
        $http->fake(['slack.com/api/*' => fn (Request $request) => $fake->handle($request)]);

        return $fake;
    }

    /**
     * Answer the next `$times` calls with HTTP 429 and a Retry-After header.
     */
    public function rateLimit(int $times = 1, int $retryAfter = 1): self
    {
        $this->rateLimits = $times;
        $this->retryAfter = $retryAfter;

        return $this;
    }

    /**
     * Answer the next call with `{"ok": false, "error": $error}`.
     */
    public function fail(string $error, int $times = 1): self
    {
        array_push($this->failures, ...array_fill(0, $times, $error));

        return $this;
    }

    /**
     * The newest message in a channel.
     *
     * @return array<string, mixed>|null
     */
    public function latest(string $channel): ?array
    {
        $messages = $this->messages($channel);

        return $messages === [] ? null : reset($messages);
    }

    /**
     * Run a callback once, the next time a method is called, before it is answered.
     * Useful for interleaving another process's writes with a slow call.
     *
     * @param  callable(array<string, mixed>): void  $callback
     */
    public function beforeNext(string $method, callable $callback): self
    {
        $this->hooks[$method][] = $callback;

        return $this;
    }

    public function createPrivateChannel(string $name): string
    {
        $id = 'G'.strtoupper(substr(sha1($name), 0, 10));
        $this->channels[$id] ??= ['name' => $name, 'members' => [$this->userId], 'messages' => []];
        $this->private[] = $id;

        return $id;
    }

    public function createChannel(string $name): string
    {
        $id = 'C'.strtoupper(substr(sha1($name), 0, 10));
        $this->channels[$id] ??= ['name' => $name, 'members' => [], 'messages' => []];

        return $id;
    }

    /**
     * Post a message as someone else (a human chatting in the cache channel, say).
     *
     * @param  array<string, mixed>  $extra
     */
    public function postAs(string $channel, string $text, array $extra = []): string
    {
        $id = $this->channelId($channel) ?? $this->createChannel($channel);
        $ts = $this->nextTs();
        $this->channels[$id]['messages'][$ts] = ['type' => 'message', 'ts' => $ts, 'text' => $text] + $extra;

        return $ts;
    }

    /**
     * Messages in a channel, newest first, keyed by ts.
     *
     * @return array<string, array<string, mixed>>
     */
    public function messages(string $channel): array
    {
        $id = $this->channelId($channel);
        $messages = $id === null ? [] : $this->channels[$id]['messages'];
        uksort($messages, fn ($a, $b) => Entry::compareTs((string) $b, (string) $a));

        return $messages;
    }

    /**
     * Thread replies under a message, oldest first, keyed by ts.
     *
     * @return array<string, array<string, mixed>>
     */
    public function replies(string $channel, string $threadTs): array
    {
        $id = $this->channelId($channel);
        $replies = $id === null ? [] : ($this->channels[$id]['replies'][$threadTs] ?? []);
        uksort($replies, fn ($a, $b) => Entry::compareTs((string) $a, (string) $b));

        return $replies;
    }

    public function callCount(?string $method = null): int
    {
        return count(array_filter($this->calls, fn (array $call) => $method === null || $call['method'] === $method));
    }

    public function channelId(string $nameOrId): ?string
    {
        $nameOrId = ltrim($nameOrId, '#');

        if (isset($this->channels[$nameOrId])) {
            return $nameOrId;
        }

        foreach ($this->channels as $id => $channel) {
            if ($channel['name'] === $nameOrId) {
                return $id;
            }
        }

        return null;
    }

    public function handle(Request $request): PromiseInterface
    {
        $method = substr($request->url(), strrpos($request->url(), '/') + 1);
        $params = $request->data();
        $this->calls[] = ['method' => $method, 'params' => $params];

        if (! str_starts_with($request->header('Authorization')[0] ?? '', 'Bearer ')) {
            return $this->error('not_authed');
        }

        if (! empty($this->hooks[$method])) {
            $hook = array_shift($this->hooks[$method]);
            $hook($params);
        }

        if ($this->failures !== []) {
            return $this->error(array_shift($this->failures));
        }

        if ($this->rateLimits > 0) {
            $this->rateLimits--;

            return Factory::response(['ok' => false, 'error' => 'ratelimited'], 429, ['Retry-After' => (string) $this->retryAfter]);
        }

        return match ($method) {
            'auth.test' => $this->ok(array_filter([
                'url' => 'https://fake.slack.com/',
                'team' => 'Fake Workspace',
                'user' => 'slackcache',
                'team_id' => 'T0FAKE',
                'user_id' => $this->userId,
                'bot_id' => $this->isBot ? $this->botId : null,
            ])),
            'conversations.list' => $this->list($params),
            'conversations.create' => $this->create($params),
            'conversations.join' => $this->join($params),
            'chat.postMessage' => $this->post($params),
            'chat.delete' => $this->delete($params),
            'chat.update' => $this->update($params),
            'conversations.history' => $this->history($params),
            'conversations.replies' => $this->conversationReplies($params),
            default => $this->error('unknown_method'),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function list(array $params): PromiseInterface
    {
        $channels = [];

        foreach ($this->channels as $id => $channel) {
            $channels[] = ['id' => $id, 'name' => $channel['name'], 'is_archived' => false];
        }

        return $this->ok(['channels' => $channels, 'response_metadata' => ['next_cursor' => '']]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function create(array $params): PromiseInterface
    {
        $name = (string) ($params['name'] ?? '');

        if ($this->channelId($name) !== null) {
            return $this->error('name_taken');
        }

        $id = $this->createChannel($name);
        $this->channels[$id]['members'][] = $this->userId;

        return $this->ok(['channel' => ['id' => $id, 'name' => $name]]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function join(array $params): PromiseInterface
    {
        $id = (string) ($params['channel'] ?? '');

        if (! isset($this->channels[$id])) {
            return $this->error('channel_not_found');
        }

        if (in_array($id, $this->private, true)) {
            return $this->error('method_not_supported_for_channel_type');
        }

        $this->channels[$id]['members'] = array_values(array_unique([...$this->channels[$id]['members'], $this->userId]));

        return $this->ok(['channel' => ['id' => $id, 'name' => $this->channels[$id]['name']]]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function post(array $params): PromiseInterface
    {
        $id = (string) ($params['channel'] ?? '');

        if (! isset($this->channels[$id])) {
            return $this->error('channel_not_found');
        }

        if (! in_array($this->userId, $this->channels[$id]['members'], true)) {
            return $this->error('not_in_channel');
        }

        $text = (string) ($params['text'] ?? '');

        if (isset($params['blocks']) && mb_strlen($text) > $this->textLimitWithBlocks) {
            $text = mb_substr($text, 0, $this->textLimitWithBlocks);
        }

        $ts = $this->nextTs();
        $message = array_filter([
            'type' => 'message',
            'ts' => $ts,
            'text' => $text,
            'user' => $this->userId,
            'bot_id' => $this->isBot ? $this->botId : null,
            'blocks' => isset($params['blocks']) ? json_decode((string) $params['blocks'], true) : null,
        ], fn ($value) => $value !== null);

        if (isset($params['thread_ts'])) {
            $threadTs = (string) $params['thread_ts'];

            if (! isset($this->channels[$id]['messages'][$threadTs])) {
                return $this->error('thread_not_found');
            }

            $message['thread_ts'] = $threadTs;
            $this->channels[$id]['replies'][$threadTs][$ts] = $message;
            $this->channels[$id]['messages'][$threadTs]['reply_count'] = count($this->channels[$id]['replies'][$threadTs]);

            return $this->ok(['channel' => $id, 'ts' => $ts, 'message' => $message]);
        }

        $this->channels[$id]['messages'][$ts] = $message;

        return $this->ok(['channel' => $id, 'ts' => $ts, 'message' => $message]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function update(array $params): PromiseInterface
    {
        $id = (string) ($params['channel'] ?? '');
        $ts = (string) ($params['ts'] ?? '');

        if (! isset($this->channels[$id]['messages'][$ts])) {
            return $this->error('message_not_found');
        }

        $message = $this->channels[$id]['messages'][$ts];

        if (($message['user'] ?? null) !== $this->userId) {
            return $this->error('cant_update_message');
        }

        $text = (string) ($params['text'] ?? '');

        if (isset($params['blocks']) && mb_strlen($text) > $this->textLimitWithBlocks) {
            $text = mb_substr($text, 0, $this->textLimitWithBlocks);
        }

        $message['text'] = $text;
        $message['blocks'] = isset($params['blocks']) ? json_decode((string) $params['blocks'], true) : null;
        $message['edited'] = ['user' => $this->userId, 'ts' => $this->nextTs()];
        $this->channels[$id]['messages'][$ts] = array_filter($message, fn ($value) => $value !== null);

        return $this->ok(['channel' => $id, 'ts' => $ts, 'text' => $text, 'message' => $this->channels[$id]['messages'][$ts]]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function delete(array $params): PromiseInterface
    {
        $id = (string) ($params['channel'] ?? '');
        $ts = (string) ($params['ts'] ?? '');

        foreach ($this->channels[$id]['replies'] ?? [] as $threadTs => $replies) {
            if (isset($replies[$ts])) {
                unset($this->channels[$id]['replies'][$threadTs][$ts]);

                // Like Slack: a deleted parent stays as a placeholder until its last reply is gone.
                if ($this->channels[$id]['replies'][$threadTs] === [] && ($this->channels[$id]['messages'][$threadTs]['subtype'] ?? null) === 'tombstone') {
                    unset($this->channels[$id]['messages'][$threadTs]);
                }

                return $this->ok(['channel' => $id, 'ts' => $ts]);
            }
        }

        if (! isset($this->channels[$id]['messages'][$ts]) || ($this->channels[$id]['messages'][$ts]['subtype'] ?? null) === 'tombstone') {
            return $this->error('message_not_found');
        }

        if (! empty($this->channels[$id]['replies'][$ts])) {
            $this->channels[$id]['messages'][$ts] = ['type' => 'message', 'subtype' => 'tombstone', 'ts' => $ts, 'text' => 'This message was deleted.', 'user' => 'USLACKBOT'];

            return $this->ok(['channel' => $id, 'ts' => $ts]);
        }

        unset($this->channels[$id]['messages'][$ts]);

        return $this->ok(['channel' => $id, 'ts' => $ts]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function history(array $params): PromiseInterface
    {
        $id = (string) ($params['channel'] ?? '');

        if (! isset($this->channels[$id])) {
            return $this->error('channel_not_found');
        }

        $limit = min((int) ($params['limit'] ?? 100), $this->historyPageSize);
        $offset = (int) ($params['cursor'] ?? 0);
        $all = array_values($this->messages($id));
        $page = array_slice($all, $offset, $limit);
        $next = $offset + $limit < count($all) ? (string) ($offset + $limit) : '';

        return $this->ok([
            'messages' => $page,
            'has_more' => $next !== '',
            'response_metadata' => ['next_cursor' => $next],
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function conversationReplies(array $params): PromiseInterface
    {
        $id = (string) ($params['channel'] ?? '');
        $ts = (string) ($params['ts'] ?? '');

        if (! isset($this->channels[$id]['messages'][$ts])) {
            return $this->error('thread_not_found');
        }

        return $this->ok([
            'messages' => [$this->channels[$id]['messages'][$ts], ...array_values($this->replies($id, $ts))],
            'has_more' => false,
            'response_metadata' => ['next_cursor' => ''],
        ]);
    }

    protected function nextTs(): string
    {
        return Carbon::now()->getTimestamp().'.'.str_pad((string) ++$this->counter, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function ok(array $data): PromiseInterface
    {
        return Factory::response(['ok' => true] + $data);
    }

    protected function error(string $error): PromiseInterface
    {
        return Factory::response(['ok' => false, 'error' => $error]);
    }
}
