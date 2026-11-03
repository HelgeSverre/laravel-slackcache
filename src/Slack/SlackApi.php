<?php

namespace HelgeSverre\SlackCache\Slack;

use HelgeSverre\SlackCache\Exceptions\SlackApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Sleep;

/**
 * The parts of the Slack Web API that SlackCache uses.
 */
class SlackApi
{
    public const string BASE_URL = 'https://slack.com/api/';

    /**
     * @param  array<string, string>  $headers  Extra request headers (a proxy header, or the `d` cookie a browser session token needs).
     */
    public function __construct(
        protected HttpFactory $http,
        #[\SensitiveParameter] protected string $token,
        protected int $timeout = 60,
        protected int $retries = 5,
        protected array $headers = [],
        protected string $baseUrl = self::BASE_URL,
    ) {}

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
                throw new SlackApiException($method, 'connection_failed', $e);
            }

            if ($response->status() === 429 && $attempt < $this->retries) {
                $attempt++;
                Sleep::for(max(1, (int) ($response->header('Retry-After') ?: 1)))->seconds();

                continue;
            }

            if (! $response->successful()) {
                throw new SlackApiException($method, 'http_'.$response->status());
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
}
