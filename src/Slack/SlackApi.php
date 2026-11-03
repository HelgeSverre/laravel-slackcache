<?php

namespace HelgeSverre\SlackCache\Slack;

use Closure;
use HelgeSverre\SlackCache\Events\SlackRateLimited;
use HelgeSverre\SlackCache\Events\SlackRequestRetrying;
use HelgeSverre\SlackCache\Exceptions\SlackApiException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;

/**
 * The parts of the Slack Web API that SlackCache uses.
 */
class SlackApi
{
    public const string BASE_URL = 'https://slack.com/api/';

    /**
     * @param  array<string, string>  $headers  Extra request headers (a proxy header, or the `d` cookie a browser session token needs).
     * @param  (Closure(): ?Dispatcher)|null  $events
     */
    public function __construct(
        protected HttpFactory $http,
        #[\SensitiveParameter] protected string $token,
        protected int $timeout = 60,
        protected int $retries = 5,
        protected array $headers = [],
        protected string $baseUrl = self::BASE_URL,
        protected ?Closure $events = null,
    ) {}

    /** @var list<string>|null */
    protected ?array $scopes = null;

    /**
     * The OAuth scopes Slack reported on the last response, or null if it did not say (browser session tokens).
     *
     * @return list<string>|null
     */
    public function scopes(): ?array
    {
        return $this->scopes;
    }

    /**
     * Resolves the event dispatcher at dispatch time, so Event::fake() in a test sees the events.
     *
     * @return (Closure(): ?Dispatcher)|null
     */
    public function events(): ?Closure
    {
        return $this->events;
    }

    protected function dispatch(object $event): void
    {
        if ($this->events !== null) {
            ($this->events)()?->dispatch($event);
        }
    }

    /**
     * What kind of token this is, by prefix.
     */
    public function tokenType(): string
    {
        return match (true) {
            str_starts_with($this->token, 'xoxb-') => 'bot',
            str_starts_with($this->token, 'xoxp-') => 'user',
            str_starts_with($this->token, 'xoxc-') => 'browser session',
            str_starts_with($this->token, 'xoxe') => 'configuration',
            default => 'unknown',
        };
    }

    /**
     * Call a Web API method. Waits out HTTP 429s using Slack's Retry-After header.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws SlackApiException
     */
    public function call(string $method, array $params = []): array
    {
        $form = [];

        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }

            $form[$key] = is_array($value) || is_bool($value)
                ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : (string) $value;
        }

        $attempt = 0;

        while (true) {
            try {
                $response = $this->http
                    ->withToken($this->token)
                    ->withHeaders($this->headers)
                    ->timeout($this->timeout)
                    ->asForm()
                    ->post($this->baseUrl.$method, $form);
            } catch (ConnectionException $e) {
                if ($attempt < $this->retries) {
                    $this->backOff($method, 'connection_failed', ++$attempt);

                    continue;
                }

                throw new SlackApiException($method, 'connection_failed', $e);
            }

            if ($response->status() === 429 && $attempt < $this->retries) {
                $attempt++;
                $retryAfter = max(1, (int) ($response->header('Retry-After') ?: 1));
                $this->dispatch(new SlackRateLimited($method, $retryAfter, $attempt));
                Sleep::for($retryAfter)->seconds();

                continue;
            }

            if ($response->serverError() && $attempt < $this->retries) {
                $this->backOff($method, 'http_'.$response->status(), ++$attempt);

                continue;
            }

            if (! $response->successful()) {
                throw new SlackApiException($method, 'http_'.$response->status());
            }

            if ($response->hasHeader('x-oauth-scopes')) {
                $this->scopes = array_values(array_filter(array_map('trim', explode(',', $response->header('x-oauth-scopes')))));
            }

            $result = $response->json();

            if (! is_array($result)) {
                throw new SlackApiException($method, 'invalid_response');
            }

            if (($result['ok'] ?? false) !== true) {
                throw new SlackApiException($method, (string) ($result['error'] ?? 'unknown_error'));
            }

            return $result;
        }
    }

    /**
     * Send a file's bytes to the upload URL files.getUploadURLExternal handed out.
     *
     * @throws SlackApiException
     */
    public function upload(string $url, string $bytes): void
    {
        $this->raw('files.upload', fn () => $this->http
            ->withHeaders($this->headers)
            ->timeout($this->timeout)
            ->withBody($bytes, 'application/octet-stream')
            ->post($url));
    }

    /**
     * Download a private file (url_private_download) with this token.
     *
     * @throws SlackApiException
     */
    public function download(string $url): string
    {
        return $this->raw('files.download', fn () => $this->http
            ->withToken($this->token)
            ->withHeaders($this->headers)
            ->timeout($this->timeout)
            ->get($url));
    }

    /**
     * A request outside the Web API (file uploads and downloads), with the same retries for server errors
     * and dropped connections.
     *
     * @param  Closure(): Response  $send
     *
     * @throws SlackApiException
     */
    protected function raw(string $label, Closure $send): string
    {
        $attempt = 0;

        while (true) {
            try {
                $response = $send();
            } catch (ConnectionException $e) {
                if ($attempt < $this->retries) {
                    $this->backOff($label, 'connection_failed', ++$attempt);

                    continue;
                }

                throw new SlackApiException($label, 'connection_failed', $e);
            }

            if (($response->serverError() || $response->status() === 429) && $attempt < $this->retries) {
                $this->backOff($label, 'http_'.$response->status(), ++$attempt);

                continue;
            }

            if (! $response->successful()) {
                throw new SlackApiException($label, 'http_'.$response->status());
            }

            return $response->body();
        }
    }

    /**
     * Wait before retrying a server error or dropped connection: 1, 2, 4 … seconds, at most 30.
     */
    protected function backOff(string $method, string $reason, int $attempt): void
    {
        $delay = min(30, 2 ** ($attempt - 1));
        $this->dispatch(new SlackRequestRetrying($method, $reason, $attempt, $delay));
        Sleep::for($delay)->seconds();
    }
}
