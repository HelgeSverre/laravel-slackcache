<?php

namespace HelgeSverre\SlackCache\Events;

/**
 * A Slack API call finished, after any retries. `error` is Slack's error code, or null when it succeeded.
 * File uploads and downloads report as `files.upload` and `files.download`.
 */
final readonly class SlackApiCalled
{
    public function __construct(
        public string $method,
        public int $milliseconds,
        public ?string $error = null,
    ) {}
}
