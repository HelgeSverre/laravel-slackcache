<?php

namespace HelgeSverre\SlackCache\Slack;

/**
 * Who writes the cache messages. A bot token is identified by its bot ID; a user token by its user ID.
 * Messages from anyone else in the channel are conversation, not cache, and are left alone.
 */
final readonly class Owner
{
    public function __construct(
        public ?string $botId,
        public ?string $userId,
    ) {}

    /**
     * @param  array<string, mixed>  $message
     */
    public function wrote(array $message): bool
    {
        if ($this->botId !== null) {
            return ($message['bot_id'] ?? null) === $this->botId;
        }

        return $this->userId !== null
            && ! isset($message['bot_id'])
            && ($message['user'] ?? null) === $this->userId;
    }
}
