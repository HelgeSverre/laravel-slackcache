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

    /**
     * Replace a message's text and blocks in place. Fails if Slack stored anything other than what was sent.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    public function update(string $ts, string $text, array $blocks): void
    {
        $result = $this->api->call('chat.update', [
            'channel' => $this->id,
            'ts' => $ts,
            'text' => $text,
            'blocks' => $blocks,
        ]);

        if (($result['message']['text'] ?? $result['text'] ?? null) !== $text) {
            throw new SlackCacheException('chat.update stored different text than was sent.');
        }
    }

    /**
     * Post a reply in a message's thread. Thread replies do not appear in the channel history.
     */
    public function reply(string $threadTs, string $text): string
    {
        $result = $this->api->call('chat.postMessage', [
            'channel' => $this->id,
            'thread_ts' => $threadTs,
            'text' => $text,
            'unfurl_links' => false,
            'unfurl_media' => false,
        ]);

        return (string) $result['ts'];
    }

    /**
     * Delete a message and this owner's replies in its thread. Replies go first: Slack keeps a deleted
     * parent as a "This message was deleted." placeholder for as long as its thread has replies.
     */
    public function deleteThread(string $ts): void
    {
        $cursor = null;
        $replies = [];

        do {
            try {
                $result = $this->api->call('conversations.replies', ['channel' => $this->id, 'ts' => $ts, 'limit' => 200, 'cursor' => $cursor]);
            } catch (SlackApiException $e) {
                if (in_array($e->error, ['thread_not_found', 'message_not_found'], true)) {
                    break;
                }

                throw $e;
            }

            foreach ($result['messages'] ?? [] as $message) {
                if ((string) $message['ts'] !== $ts && $this->owner->wrote($message)) {
                    $replies[] = (string) $message['ts'];
                }
            }

            $cursor = $result['response_metadata']['next_cursor'] ?? null;
        } while ($cursor);

        foreach ($replies as $reply) {
            $this->delete($reply);
        }

        $this->delete($ts);
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
