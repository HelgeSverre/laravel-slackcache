<?php

namespace HelgeSverre\SlackCache;

use Carbon\CarbonImmutable;
use HelgeSverre\SlackCache\Exceptions\ValueTooLargeException;

/**
 * One Slack message per key: a machine-readable `text` and a human-readable preview.
 *
 * The text format is byte-for-byte the one used by the original Python SlackCache
 * (`slackcache:v1 <base64 key> <expiry ms or -> <base64 value>`), so a channel can be
 * shared between this driver and that server.
 */
final class Codec
{
    public const string MARKER = 'slackcache:v1';

    /** Measured by the original project: Slack cuts `text` to 16,000 characters when a message has blocks. */
    public const int MAX_TEXT = 16_000;

    public const int PREVIEW = 1_500;

    /**
     * @param  list<string>  $tags  Cache tags, shown in the preview.
     * @param  list<string>  $emoji  Slack emoji names shown before the header, such as `lock`.
     * @return array{0: string, 1: list<array<string, mixed>>}
     *
     * @throws ValueTooLargeException
     */
    public static function encode(string $key, string $value, ?int $expireAt = null, ?string $preview = null, array $tags = [], array $emoji = []): array
    {
        $text = implode(' ', [
            self::MARKER,
            base64_encode($key),
            $expireAt === null ? '-' : (string) $expireAt,
            base64_encode($value),
        ]);

        if (strlen($text) > self::MAX_TEXT) {
            throw ValueTooLargeException::forKey($key, strlen($text), self::MAX_TEXT);
        }

        $header = [
            ...array_map(fn (string $name) => ['type' => 'emoji', 'name' => $name], $emoji),
            ...($emoji === [] ? [] : [['type' => 'text', 'text' => ' ']]),
            ['type' => 'text', 'text' => 'SET ', 'style' => ['bold' => true]],
            ['type' => 'text', 'text' => self::show($key, 200), 'style' => ['code' => true]],
            ['type' => 'text', 'text' => self::expiryNote($expireAt).($tags === [] ? '' : ' · tags: '.implode(', ', $tags))],
        ];

        $blocks = [[
            'type' => 'rich_text',
            'elements' => [
                ['type' => 'rich_text_section', 'elements' => $header],
                ['type' => 'rich_text_preformatted', 'elements' => [
                    ['type' => 'text', 'text' => self::show($preview ?? $value, self::PREVIEW)],
                ]],
            ],
        ]];

        return [$text, $blocks];
    }

    /**
     * Return [key, value, expireAt] from a message's text, or null if the message is not ours.
     *
     * @return array{0: string, 1: string, 2: int|null}|null
     */
    public static function decode(?string $text): ?array
    {
        $parts = explode(' ', (string) $text);

        if (count($parts) !== 4 || $parts[0] !== self::MARKER) {
            return null;
        }

        $key = base64_decode($parts[1], true);
        $value = base64_decode($parts[3], true);

        if ($key === false || $value === false) {
            return null;
        }

        if ($parts[2] === '-') {
            return [$key, $value, null];
        }

        if (! preg_match('/^-?\d+$/', $parts[2])) {
            return null;
        }

        return [$key, $value, (int) $parts[2]];
    }

    private static function show(string $data, int $limit): string
    {
        $text = mb_scrub($data, 'UTF-8');

        if ($text === '') {
            return '(empty string)';
        }

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit).'… ('.number_format(strlen($data)).' bytes)';
    }

    private static function expiryNote(?int $expireAt): string
    {
        if ($expireAt === null) {
            return ' · no TTL (the free plan evicts it in 90 days)';
        }

        $at = CarbonImmutable::createFromTimestampMsUTC($expireAt);

        return ' · expires '.$at->format('Y-m-d H:i:s').' UTC';
    }
}
