<?php

namespace HelgeSverre\SlackCache\Exceptions;

/**
 * A Slack Web API call failed. The message is safe to log: it never contains the token.
 */
class SlackApiException extends SlackCacheException
{
    public function __construct(public readonly string $method, public readonly string $error, ?\Throwable $previous = null)
    {
        parent::__construct("Slack API call {$method} failed: {$error}", 0, $previous);
    }
}
