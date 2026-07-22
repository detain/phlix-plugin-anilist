<?php

/**
 * AniList HTTP Client.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Metadata\AniList;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Non-blocking HTTP client for the AniList GraphQL API.
 *
 * Uses the canonical Workerman cooperative-wait pattern (see phlix-server
 * CLAUDE.md "Async Patterns"): the request is dispatched through
 * {@see \Workerman\Http\Client} and the caller yields to the event loop with a
 * bounded 1ms tick until the async callback resolves. This never stalls the
 * resident worker the way the previous synchronous cURL implementation did.
 *
 * AniList rate-limits at roughly 90 requests/minute and answers a breach with
 * HTTP 429. {@see request()} honours a `Retry-After` header (falling back to
 * exponential backoff) and retries transparently, using a cooperative sleep so
 * the backoff does not block the loop either.
 *
 * @package Phlix\Plugins\Metadata\AniList
 * @since 0.14.0
 */
class HttpClient implements HttpClientInterface
{
    /** Maximum cooperative wait for a single request, in seconds. */
    private const MAX_WAIT_SEC = 30.0;

    /** Cooperative tick interval, in seconds (1ms). */
    private const TICK_SEC = 0.001;

    /** Maximum automatic retries on an HTTP 429 response. */
    private const MAX_RETRIES = 3;

    /** Base backoff (seconds) when the server sends no usable Retry-After. */
    private const DEFAULT_BACKOFF_SEC = 2.0;

    private readonly LoggerInterface $logger;

    /**
     * @param LoggerInterface|null $logger Optional PSR-3 logger
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * {@inheritdoc}
     */
    public function post(string $url, array $data, array $headers = []): array
    {
        return $this->request('POST', $url, $data, $headers);
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $url, array $params = [], array $headers = []): array
    {
        if ($params !== []) {
            $url .= '?' . http_build_query($params);
        }

        return $this->request('GET', $url, [], $headers);
    }

    /**
     * Execute an HTTP request with transparent 429 backoff/retry.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param array<string, mixed> $data Request body (for POST/PUT)
     * @param array<string, string> $headers HTTP headers
     *
     * @return array<string, mixed> Decoded JSON response
     *
     * @throws AniListApiException On request failure or after retries are exhausted
     */
    private function request(string $method, string $url, array $data, array $headers): array
    {
        $attempt = 0;

        while (true) {
            $raw = $this->sendOnce($method, $url, $data, $headers);
            $status = $raw['status'];

            if ($status === 429) {
                if ($attempt >= self::MAX_RETRIES) {
                    throw new AniListApiException(
                        'AniList API rate limit exceeded after ' . self::MAX_RETRIES . ' retries'
                    );
                }

                $wait = $this->retryAfterSeconds($raw['retry_after'], $attempt);
                $this->logger->warning('AniList API rate-limited (HTTP 429); backing off', [
                    'attempt' => $attempt + 1,
                    'wait_seconds' => $wait,
                    'url' => $url,
                ]);
                $this->backoffSleep($wait);
                $attempt++;
                continue;
            }

            if ($status >= 400) {
                throw new AniListApiException('AniList API returned HTTP ' . $status . ': ' . $raw['body']);
            }

            $decoded = json_decode($raw['body'], true);
            if (!is_array($decoded)) {
                throw new AniListApiException('Invalid JSON response from AniList API');
            }

            $this->logger->debug('AniList API response', [
                'method' => $method,
                'url' => $url,
                'http_code' => $status,
            ]);

            return $decoded;
        }
    }

    /**
     * Dispatch a single request via the Workerman async client and cooperatively
     * wait for it to resolve.
     *
     * Extracted as a protected seam so the retry/backoff loop in
     * {@see request()} can be unit-tested without a live Workerman runtime.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param array<string, mixed> $data Request body
     * @param array<string, string> $headers HTTP headers
     *
     * @return array{status: int, body: string, retry_after: string|null}
     *
     * @throws AniListApiException On transport failure or timeout
     */
    protected function sendOnce(string $method, string $url, array $data, array $headers): array
    {
        if (!class_exists(\Workerman\Http\Client::class)) {
            throw new AniListApiException('Workerman HTTP client is unavailable in this runtime');
        }

        $state = [
            'done' => false,
            'status' => 0,
            'body' => '',
            'retry_after' => null,
            'error' => null,
        ];

        $client = new \Workerman\Http\Client(['timeout' => (int) self::MAX_WAIT_SEC]);

        $options = [
            'method' => $method,
            'headers' => $headers,
            'success' => function ($response) use (&$state): void {
                $state['status'] = (int) $response->getStatusCode();
                $state['body'] = (string) $response->getBody();
                $retryAfter = $response->getHeaderLine('Retry-After');
                $state['retry_after'] = $retryAfter !== '' ? $retryAfter : null;
                $state['done'] = true;
            },
            'error' => function ($error) use (&$state): void {
                $state['error'] = is_object($error) && method_exists($error, 'getMessage')
                    ? $error->getMessage()
                    : (is_scalar($error) ? (string) $error : 'unknown transport error');
                $state['done'] = true;
            },
        ];

        if ($method === 'POST') {
            $options['data'] = (string) json_encode($data);
        }

        $client->request($url, $options);

        // Cooperative wait — yields to the event loop so other tasks proceed.
        $waited = 0.0;
        while ($state['done'] === false && $waited < self::MAX_WAIT_SEC) {
            $this->tick();
            $waited += self::TICK_SEC;
        }

        if ($state['error'] !== null) {
            throw new AniListApiException('AniList HTTP error: ' . $state['error']);
        }

        if ($state['done'] === false) {
            throw new AniListApiException('AniList HTTP request timed out');
        }

        return [
            'status' => $state['status'],
            'body' => $state['body'],
            'retry_after' => $state['retry_after'],
        ];
    }

    /**
     * Cooperative 1ms tick that yields to the Workerman event loop.
     *
     * Protected so tests can stub it out (avoiding real time in the suite).
     *
     * @return void
     */
    protected function tick(): void
    {
        usleep((int) (self::TICK_SEC * 1_000_000));
    }

    /**
     * Cooperatively sleep for the backoff interval.
     *
     * Prefers {@see \Workerman\Timer::sleep()} (yields the coroutine to the loop)
     * and falls back to a plain sleep outside a Workerman runtime. Protected so
     * tests can assert the backoff was invoked without actually waiting.
     *
     * @param float $seconds Seconds to back off
     *
     * @return void
     */
    protected function backoffSleep(float $seconds): void
    {
        if ($seconds <= 0.0) {
            return;
        }

        if (class_exists(\Workerman\Timer::class) && method_exists(\Workerman\Timer::class, 'sleep')) {
            \Workerman\Timer::sleep($seconds);
            return;
        }

        usleep((int) ($seconds * 1_000_000));
    }

    /**
     * Resolve the backoff interval for a 429, honouring Retry-After when present.
     *
     * @param string|null $retryAfter Raw Retry-After header value (delta-seconds)
     * @param int $attempt Zero-indexed retry attempt
     *
     * @return float Seconds to wait
     */
    private function retryAfterSeconds(?string $retryAfter, int $attempt): float
    {
        if ($retryAfter !== null && is_numeric($retryAfter)) {
            return max(0.0, (float) $retryAfter);
        }

        // Exponential backoff: 2s, 4s, 8s ...
        return self::DEFAULT_BACKOFF_SEC * (2 ** $attempt);
    }
}
