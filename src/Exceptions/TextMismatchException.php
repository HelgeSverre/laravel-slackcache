<?php

namespace HelgeSverre\SlackCache\Exceptions;

/**
 * Slack stored different text than was sent: it truncated or rewrote the message.
 */
class TextMismatchException extends SlackCacheException {}
