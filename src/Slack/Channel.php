<?php

namespace HelgeSverre\SlackCache\Slack;

use Generator;
use HelgeSverre\SlackCache\Exceptions\SlackApiException;
use HelgeSverre\SlackCache\Exceptions\TextMismatchException;

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

            throw new TextMismatchException('chat.postMessage stored different text than was sent; the write was rolled back.');
        }

        return $ts;
    }

    /**
     * Post a plain message and delete it again. Proves the token can write here.
     */
    public function probe(string $text): void
    {
        $ts = (string) $this->api->call('chat.postMessage', ['channel' => $this->id, 'text' => $text])['ts'];
        $this->delete($ts);
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
            throw new TextMismatchException('chat.update stored different text than was sent.');
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

    /**
     * Add a reaction. Adding one that is already there is not an error.
     */
    public function react(string $ts, string $name): void
    {
        try {
            $this->api->call('reactions.add', ['channel' => $this->id, 'timestamp' => $ts, 'name' => $name]);
        } catch (SlackApiException $e) {
            if ($e->error !== 'already_reacted') {
                throw $e;
            }
        }
    }

    /**
     * Remove this owner's reaction. Removing one that is not there is not an error.
     */
    public function unreact(string $ts, string $name): void
    {
        try {
            $this->api->call('reactions.remove', ['channel' => $this->id, 'timestamp' => $ts, 'name' => $name]);
        } catch (SlackApiException $e) {
            if (! in_array($e->error, ['no_reaction', 'message_not_found'], true)) {
                throw $e;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<string>
     */
    protected function ownReactions(array $message): array
    {
        $names = [];

        foreach ($message['reactions'] ?? [] as $reaction) {
            if ($this->owner->userId !== null && in_array($this->owner->userId, $reaction['users'] ?? [], true)) {
                $names[] = (string) $reaction['name'];
            }
        }

        return $names;
    }

    /**
     * Upload bytes as a file that is not shared in any channel, and return its ID. Only this token can read it.
     */
    public function upload(string $filename, string $title, string $bytes): string
    {
        $target = $this->api->call('files.getUploadURLExternal', ['filename' => $filename, 'length' => strlen($bytes)]);
        $id = (string) $target['file_id'];

        try {
            $this->api->upload((string) $target['upload_url'], $bytes);
            $this->api->call('files.completeUploadExternal', ['files' => [['id' => $id, 'title' => $title]]]);
        } catch (\Throwable $e) {
            try {
                $this->deleteFile($id);
            } catch (\Throwable) {
                // The upload error is the one worth reporting.
            }

            throw $e;
        }

        return $id;
    }

    /**
     * The bytes of a file this token can read.
     */
    public function download(string $file): string
    {
        $info = $this->api->call('files.info', ['file' => $file]);

        return $this->api->download((string) ($info['file']['url_private_download'] ?? ''));
    }

    /**
     * Delete a file. Deleting one that is already gone is not an error.
     */
    public function deleteFile(string $file): void
    {
        try {
            $this->api->call('files.delete', ['file' => $file]);
        } catch (SlackApiException $e) {
            if (! in_array($e->error, ['file_not_found', 'file_deleted'], true)) {
                throw $e;
            }
        }
    }

    public function topic(): string
    {
        return (string) ($this->api->call('conversations.info', ['channel' => $this->id])['channel']['topic']['value'] ?? '');
    }

    /**
     * Set the channel topic. Slack announces it with a "set the channel topic" message; that notice is
     * deleted again unless `$keepNotice`, so the channel holds cache messages and nothing else.
     */
    public function setTopic(string $topic, bool $keepNotice = false): void
    {
        $this->api->call('conversations.setTopic', ['channel' => $this->id, 'topic' => $topic]);

        if ($keepNotice) {
            return;
        }

        try {
            $recent = $this->api->call('conversations.history', ['channel' => $this->id, 'limit' => 5])['messages'] ?? [];

            foreach ($recent as $message) {
                if (($message['subtype'] ?? null) === 'channel_topic' && ($message['user'] ?? null) === $this->owner->userId) {
                    $this->delete((string) $message['ts']);
                }
            }
        } catch (SlackApiException) {
            // The topic is set. A notice left behind is cosmetic.
        }
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
     * Yield [ts, text, reactions] for this owner's messages, newest first. Reactions are the names of the
     * reactions this owner added.
     *
     * @return Generator<int, array{0: string, 1: string, 2: list<string>}>
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
                    yield [(string) $message['ts'], (string) ($message['text'] ?? ''), $this->ownReactions($message)];
                }
            }

            $cursor = $result['response_metadata']['next_cursor'] ?? null;
        } while ($cursor);
    }
}
