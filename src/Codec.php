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

    /** Starts the value field of a message whose value is in a Slack file: `@<file ID>:<sha256 of the value>`. */
    public const string FILE = '@';

    /**
     * @param  list<string>  $tags  Cache tags, shown in the preview.
     * @param  list<string>  $emoji  Slack emoji names shown before the header, such as `lock`.
     * @param  string|null  $file  The ID of a Slack file holding the value. The text then points to it instead of holding it.
     * @return array{0: string, 1: list<array<string, mixed>>}
     *
     * @throws ValueTooLargeException
     */
    public static function encode(string $key, string $value, ?int $expireAt = null, ?string $preview = null, array $tags = [], array $emoji = [], ?string $file = null): array
    {
        $text = implode(' ', [
            self::MARKER,
            base64_encode($key),
            $expireAt === null ? '-' : (string) $expireAt,
            $file === null ? base64_encode($value) : self::FILE.$file.':'.hash('sha256', $value),
        ]);

        if (strlen($text) > self::MAX_TEXT) {
            throw ValueTooLargeException::forKey($key, strlen($text), self::MAX_TEXT);
        }

        $header = [
            ...array_map(fn (string $name) => ['type' => 'emoji', 'name' => $name], $emoji),
            ...($emoji === [] ? [] : [['type' => 'text', 'text' => ' ']]),
            ['type' => 'text', 'text' => 'SET ', 'style' => ['bold' => true]],
            ['type' => 'text', 'text' => self::show($key, 200), 'style' => ['code' => true]],
            ['type' => 'text', 'text' => self::expiryNote($expireAt).($tags === [] ? '' : ' · tags: '.implode(', ', $tags))
                .($file === null ? '' : ' · stored as a file, '.number_format(strlen($value)).' bytes')],
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
     * Return [key, value, expireAt, file] from a message's text, or null if the message is not ours.
     *
     * When the value is in a Slack file, value is '' and file is [file ID, sha256 of the value]. Otherwise file is null.
     *
     * @return array{0: string, 1: string, 2: int|null, 3: array{0: string, 1: string}|null}|null
     */
    public static function decode(?string $text): ?array
    {
        $parts = explode(' ', (string) $text);

        if (count($parts) !== 4 || $parts[0] !== self::MARKER) {
            return null;
        }

        $key = base64_decode($parts[1], true);
        $file = null;

        if (str_starts_with($parts[3], self::FILE)) {
            if (! preg_match('/^@([A-Z0-9]+):([0-9a-f]{64})$/', $parts[3], $match)) {
                return null;
            }

            [$value, $file] = ['', [$match[1], $match[2]]];
        } else {
            $value = base64_decode($parts[3], true);
        }

        if ($key === false || $value === false) {
            return null;
        }

        if ($parts[2] === '-') {
            return [$key, $value, null, $file];
        }

        if (! preg_match('/^-?\d+$/', $parts[2])) {
            return null;
        }

        return [$key, $value, (int) $parts[2], $file];
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
