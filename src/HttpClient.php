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
 * TLS posture: the host-provided workerman/http-client pool defaults to
 * certificate verification OFF, so every request here carries the strict
 * context built by {@see self::strictTlsContext()} — verification, peer-name
 * check, no self-signed acceptance, explicit SNI anchored to the request host.
 * The bearer token in the Authorization header makes an unverified channel a
 * token-theft + metadata-injection vector; there is deliberately no opt-out.
 *
 * AniList rate-limits at roughly 90 requests/minute and answers a breach with
 * HTTP 429. {@see request()} honours a `Retry-After` header (falling back to
 * exponential backoff) and retries transparently, using a cooperative sleep so
 * the backoff does not block the loop either. A server-supplied delay above
 * {@see self::MAX_BACKOFF_SEC} is not obeyed — AniList's own limit windows are
 * short, so anything longer is a misconfiguration or a hostile header, and
 * waiting it out would stall the resident sync worker (M-ANILIST-09).
 *
 * @package Phlix\Plugins\Metadata\AniList
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

    /**
     * Ceiling (seconds) for a server-supplied Retry-After delay.
     *
     * AniList's rate-limit windows are seconds-to-minutes short, so a longer
     * value is a misconfigured proxy or an adversarial header. Obeying it
     * would park the resident cooperative worker for its full duration; the
     * ladder in {@see retryAfterSeconds()} handles those attempts instead.
     */
    private const MAX_BACKOFF_SEC = 60.0;

    private readonly LoggerInterface $logger;

    /**
     * Vendor clients memoized per remote host.
     *
     * Fresh-constructing \Workerman\Http\Client inside sendOnce() built a new
     * connection pool — and paid a fresh TLS handshake — per request,
     * including per 429 retry. One instance per host amortizes the vendor's
     * own connection pooling across the client's lifetime. The construction
     * stays at the single site with the inline strict-context shape pinned by
     * {@see \Phlix\Tests\Unit\Plugins\Metadata\AniList\AniListHttpClientTlsTest}.
     *
     * @var array<string, \Workerman\Http\Client>
     */
    private array $vendorClients = [];

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

        $parsedHost = parse_url($url, PHP_URL_HOST);
        $hostKey = is_string($parsedHost) && $parsedHost !== '' ? $parsedHost : $url;

        if (!isset($this->vendorClients[$hostKey])) {
            $client = new \Workerman\Http\Client([
                'timeout' => (int) self::MAX_WAIT_SEC,
                'context' => self::strictTlsContext($url),
            ]);
            $this->vendorClients[$hostKey] = $client;
        }

        $client = $this->vendorClients[$hostKey];

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
                $state['error'] = is_object($error) === true && method_exists($error, 'getMessage') === true
                    ? $error->getMessage()
                    : (is_scalar($error) === true ? (string) $error : 'unknown transport error');
                $state['done'] = true;
            },
        ];

        if ($method === 'POST') {
            $options['data'] = (string) json_encode($data);
        }

        $client->request($url, $options);

        // Cooperative wait — yields to the event loop so other tasks proceed.
        // The budget is measured against monotonic wall time, not a count of
        // nominal ticks: on a loaded worker each tick can take far longer than
        // TICK_SEC, and counting ticks would silently stretch the real wait by
        // the overrun factor (M-ANILIST-17).
        $deadlineNs = hrtime(true) + (int) (self::MAX_WAIT_SEC * 1_000_000_000);
        while ($state['done'] === false && hrtime(true) < $deadlineNs) {
            $this->tick();
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
     * Build a strict TLS stream context for the given request URL.
     *
     * The host-provided workerman/http-client pool defaults to
     * `verify_peer => false, verify_peer_name => false, allow_self_signed =>
     * true` (v3.1.3 ConnectionPool::create()) and SHALLOW-merges a caller-
     * supplied `context` over those defaults, so the caller must pass a FULL
     * `ssl` section to turn verification on — a partial one would leave the
     * remaining lax defaults in place. This mirrors the estate idiom shipped
     * for the same vendor family by phlix-hub (519c0c0) and
     * phlix-server/src/Hub/HttpClient.php.
     *
     * `peer_name` is pinned to the URL host so a connection pooled/reused for
     * a different host cannot silently inherit this context, and SNI is sent
     * explicitly (the vendor only auto-sets peer_name on proxy paths).
     *
     * @param string $url The request URL whose host anchors the peer identity.
     * @return array{ssl: array<string, mixed>} A complete `ssl` stream context.
     */
    private static function strictTlsContext(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);

        return ['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'peer_name' => is_string($host) && $host !== '' ? $host : 'graphql.anilist.co',
        ]];
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
     * A numeric Retry-After within {@see self::MAX_BACKOFF_SEC} is obeyed as the
     * server asked. Above the ceiling it is NOT — AniList's own limit windows are
     * short, so a multi-minute delay means a broken proxy or a hostile header,
     * and a cooperative worker must not park on it; such attempts fall back to
     * the exponential ladder (M-ANILIST-09).
     *
     * @param string|null $retryAfter Raw Retry-After header value (delta-seconds)
     * @param int $attempt Zero-indexed retry attempt
     *
     * @return float Seconds to wait
     */
    private function retryAfterSeconds(?string $retryAfter, int $attempt): float
    {
        if ($retryAfter !== null && is_numeric($retryAfter) === true) {
            $seconds = max(0.0, (float) $retryAfter);
            if ($seconds <= self::MAX_BACKOFF_SEC) {
                return $seconds;
            }
        }

        // Exponential backoff: 2s, 4s, 8s ...
        return self::DEFAULT_BACKOFF_SEC * (2 ** $attempt);
    }
}
