<?php

namespace HelgeSverre\SlackCache\Slack;

/**
 * Finds, creates and joins the cache channels, listing the workspace at most once.
 */
class ChannelResolver
{
    public function __construct(protected SlackApi $api) {}

    /**
     * @param  list<string>  $names  Channel names (with or without #) or channel IDs.
     * @return array{owner: array{bot_id: string|null, user_id: string|null}, channels: list<array{id: string, name: string}>}
     */
    public function resolve(array $names): array
    {
        $auth = $this->api->call('auth.test');
        $owner = [
            'bot_id' => isset($auth['bot_id']) ? (string) $auth['bot_id'] : null,
            'user_id' => isset($auth['user_id']) ? (string) $auth['user_id'] : null,
        ];

        $names = array_map(fn (string $name) => ltrim($name, '#'), $names);
        $known = [];

        if (array_filter($names, fn (string $name) => ! self::looksLikeId($name)) !== []) {
            $known = $this->listChannels();
        }

        $channels = [];

        foreach ($names as $name) {
            $id = self::looksLikeId($name) ? $name : ($known[$name] ?? null);

            if ($id === null) {
                $id = (string) $this->api->call('conversations.create', ['name' => $name])['channel']['id'];
            }

            $this->api->call('conversations.join', ['channel' => $id]);
            $channels[] = ['id' => $id, 'name' => $name];
        }

        return ['owner' => $owner, 'channels' => $channels];
    }

    /**
     * @return array<string, string> channel name => ID
     */
    protected function listChannels(): array
    {
        $known = [];
        $cursor = null;

        do {
            $result = $this->api->call('conversations.list', [
                'types' => 'public_channel',
                'exclude_archived' => true,
                'limit' => 1000,
                'cursor' => $cursor,
            ]);

            foreach ($result['channels'] ?? [] as $channel) {
                $known[(string) $channel['name']] = (string) $channel['id'];
            }

            $cursor = $result['response_metadata']['next_cursor'] ?? null;
        } while ($cursor);

        return $known;
    }

    /**
     * Check a resolution read back from the replica. Returns null if it is missing or malformed.
     *
     * @return array{owner: array{bot_id: string|null, user_id: string|null}, channels: list<array{id: string, name: string}>}|null
     */
    public static function validate(mixed $resolved, int $expectedChannels): ?array
    {
        if (! is_array($resolved) || ! is_array($resolved['owner'] ?? null) || ! is_array($resolved['channels'] ?? null)) {
            return null;
        }

        $botId = $resolved['owner']['bot_id'] ?? null;
        $userId = $resolved['owner']['user_id'] ?? null;

        if ((! is_string($botId) && $botId !== null) || (! is_string($userId) && $userId !== null)) {
            return null;
        }

        $channels = [];

        foreach ($resolved['channels'] as $channel) {
            if (! is_array($channel) || ! is_string($channel['id'] ?? null) || ! is_string($channel['name'] ?? null)) {
                return null;
            }

            $channels[] = ['id' => $channel['id'], 'name' => $channel['name']];
        }

        if (count($channels) !== $expectedChannels) {
            return null;
        }

        return ['owner' => ['bot_id' => $botId, 'user_id' => $userId], 'channels' => $channels];
    }

    public static function looksLikeId(string $name): bool
    {
        return (bool) preg_match('/^[CG][A-Z0-9]{8,}$/', $name);
    }
}
