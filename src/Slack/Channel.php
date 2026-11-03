<?php

namespace HelgeSverre\SlackCache\Slack;

use Generator;
use HelgeSverre\SlackCache\Exceptions\SlackApiException;
use HelgeSverre\SlackCache\Exceptions\SlackCacheException;

/**
 * A channel that stores one message per key, written by this token's bot (or user).
 */
class Channel
{
    public function __construct(
        protected SlackApi $api,
        public readonly string $id,
        public readonly string $name,
        protected Owner $owner,
    ) {}

    /**
     * Post a message and return its timestamp. Fails if Slack stored anything other than what was sent.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    public function post(string $text, array $blocks): string
    {
        $result = $this->api->call('chat.postMessage', [
            'channel' => $this->id,
            'text' => $text,
            'blocks' => $blocks,
            'unfurl_links' => false,
            'unfurl_media' => false,
        ]);

        $ts = (string) $result['ts'];

        if (($result['message']['text'] ?? null) !== $text) {
            $this->delete($ts);

            throw new SlackCacheException('chat.postMessage stored different text than was sent; the write was rolled back.');
        }

        return $ts;
    }

    public function delete(string $ts): void
    {
        try {
            $this->api->call('chat.delete', ['channel' => $this->id, 'ts' => $ts]);
        } catch (SlackApiException $e) {
            if ($e->error !== 'message_not_found') {
                throw $e;
            }
        }
    }

    /**
     * Yield [ts, text] for this owner's messages, newest first.
     *
     * @return Generator<int, array{0: string, 1: string}>
     */
    public function messages(): Generator
    {
        $cursor = null;

        do {
            $result = $this->api->call('conversations.history', [
                'channel' => $this->id,
                'limit' => 200,
                'cursor' => $cursor,
            ]);

            foreach ($result['messages'] ?? [] as $message) {
                if (! isset($message['subtype']) && $this->owner->wrote($message)) {
                    yield [(string) $message['ts'], (string) ($message['text'] ?? '')];
                }
            }

            $cursor = $result['response_metadata']['next_cursor'] ?? null;
        } while ($cursor);
    }
}
