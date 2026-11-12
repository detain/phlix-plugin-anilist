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

    /**
     * Ceiling (bytes) for a buffered success-path response body.
     *
     * The error path deliberately worries about flood-capable payloads and
     * bounds its excerpt (safeErrorBodySnippet); the success path buffered
     * and json_decode()d whatever arrived with no ceiling at all. A hostile
     * or compromised upstream answering 200 with a gigabyte JSON inflates
     * the resident worker through the decoder (decode multiplies memory
     * several-fold) and the retained copy. Strict TLS to a fixed endpoint
     * keeps this defense-in-depth, and the vendor buffers the message
     * before this seam sees it — what the ceiling guarantees is that no
     * response above it is ever decoded, duplicated into handler state,
     * or kept alive in a resident worker.
     */
    private const MAX_RESPONSE_BYTES = 8 * 1024 * 1024;

    /**
     * Ceiling (characters) for the remote error-body excerpt carried in an
     * exception message (see {@see self::safeErrorBodySnippet()}).
     */
    private const MAX_ERROR_BODY_SNIPPET = 500;

    /**
     * Ceiling (bytes) of a remote error body that the scrub in
     * {@see self::safeErrorBodySnippet()} is allowed to scan (ANL-6).
     *
     * The excerpt was capped only AFTER the control-char regex, the
     * whitespace collapse and mb_strlen() had each walked the WHOLE body —
     * and the >= 400 throw in {@see request()} precedes the
     * {@see self::MAX_RESPONSE_BYTES} check, so a hostile 4xx/5xx body of
     * any size was scanned several times over (plus a full-size copy) just
     * to keep its first 500 characters. The body is now cut to this many
     * bytes first. Four bytes per character is the UTF-8 worst case, so a
     * clean body still yields the full 500-character excerpt; the slack
     * absorbs a little collapsed whitespace/control noise.
     */
    private const MAX_ERROR_BODY_SCAN_BYTES = self::MAX_ERROR_BODY_SNIPPET * 4 + 16;

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
                        'AniList API rate limit exceeded after ' . self::MAX_RETRIES . ' retries',
                        429,
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
                throw new AniListApiException(
                    'AniList API returned HTTP ' . $status . ': ' . self::safeErrorBodySnippet($raw['body']),
                    $status,
                );
            }

            if (strlen($raw['body']) > self::MAX_RESPONSE_BYTES) {
                // Fail the request above the ceiling — an oversized success
                // body is an API failure, never an absence (isNotFound stays
                // false, so no lookup can memoize it as "no entry").
                throw new AniListApiException(
                    'AniList response body exceeded the '
                    . intdiv(self::MAX_RESPONSE_BYTES, 1024 * 1024) . ' MiB response ceiling'
                );
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
     * Bound a remote error body for carriage inside an exception message.
     *
     * Any 4xx/5xx body — and the vendor transport error text the Workerman
     * client hands the error callback, which reaches us through the same
     * exception channel — is fully remote-controlled: unbounded size floods
     * the log stream, embedded newlines forge log entries, and ANSI/bidi
     * control characters poison terminal log viewers — and call sites log
     * this message verbatim (`'error' => $e->getMessage()`). Only a
     * control-char stripped, whitespace-collapsed, length-capped printable
     * excerpt is allowed to travel; the full body never leaves this method
     * (and is not even logged at debug here — a flood-capable payload should
     * not get a second ride into the log stream). The `HTTP <status>` prefix
     * kept by the caller stays for human readers of the log line; the
     * not-found classification AniListApi performs is TYPED (the answering
     * status rides on the exception itself, see
     * {@see AniListApiException::httpStatus()}).
     *
     * Shared with {@see AniListApi::formatGraphQLErrors()}: the HTTP-200
     * GraphQL errors envelope carries the same remote-controlled prose and
     * gets exactly this scrub-and-cap per message.
     *
     * Only the first {@see self::MAX_ERROR_BODY_SCAN_BYTES} bytes are ever
     * scanned: the cut happens BEFORE the scrub (on a UTF-8 character
     * boundary, so a clean multibyte body is not knocked into the ASCII
     * fallback), and a cut body is `[truncated]` marked even when its
     * scrubbed excerpt lands under the character cap.
     *
     * @param string $body Raw remote response body or transport error text
     *
     * @return string Printable excerpt (≤ {@see self::MAX_ERROR_BODY_SNIPPET}
     *                characters, `[truncated]` marked when capped;
     *                '(no printable body)' when nothing was displayable)
     */
    public static function safeErrorBodySnippet(string $body): string
    {
        $truncated = false;
        if (strlen($body) > self::MAX_ERROR_BODY_SCAN_BYTES) {
            // mb_strcut() backs off to the start of a split multibyte
            // sequence, so the cut never manufactures invalid UTF-8.
            $body = mb_strcut($body, 0, self::MAX_ERROR_BODY_SCAN_BYTES, 'UTF-8');
            $truncated = true;
        }

        // Newlines are the log-forgery vector, so they collapse to spaces
        // first; the remaining C0/C1, DEL, bidi and zero-width families are
        // display poison and are removed outright.
        $flat = preg_replace(
            '/[\x00-\x1F\x7F\x{0080}-\x{009F}\x{200B}-\x{200F}'
            . '\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]+/u',
            ' ',
            $body
        );

        if (is_string($flat) === false) {
            // Invalid UTF-8: fall back to printable ASCII only.
            $flat = preg_replace('/[^\x20-\x7E]+/', ' ', $body) ?? '';
        }

        $flat = trim(preg_replace('/ {2,}/', ' ', $flat) ?? '');
        if (mb_strlen($flat) > self::MAX_ERROR_BODY_SNIPPET) {
            $flat = mb_substr($flat, 0, self::MAX_ERROR_BODY_SNIPPET);
            $truncated = true;
        }

        if ($flat === '') {
            return '(no printable body)';
        }

        return $truncated === true ? $flat . '[truncated]' : $flat;
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

        /**
         * Cooperative-wait state. The shape is DECLARED (register PG-167):
         * before this, the literal's type was only ever "proven" by the
         * happy path, and the closures below wrote whatever the vendor
         * handed them into it.
         *
         * @var array{done: bool, status: int, body: string, retry_after: string|null, error: string|null} $state
         */
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
                // The vendor surface is untyped here (mixed); the declared
                // shape demands the string actually be narrowed.
                $state['retry_after'] = is_string($retryAfter) === true && $retryAfter !== ''
                    ? $retryAfter
                    : null;
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
            // Vendor transport text is remote-influenced (a proxy's error
            // page, a TLS-layer string): it gets the same scrub-and-cap the
            // 4xx/5xx bodies get, because every caller logs this message
            // verbatim ('error' => $e->getMessage()). No status is attached:
            // a transport failure answered with no HTTP status at all, and
            // "no status" must never classify as not-found.
            throw new AniListApiException(
                'AniList HTTP error: ' . self::safeErrorBodySnippet($state['error'])
            );
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
     * the exponential ladder (M-ANILIST-09). A negative or non-finite value is
     * malformed and takes the ladder too; a literal 0 is obeyed (retry now).
     *
     * @param string|null $retryAfter Raw Retry-After header value (delta-seconds)
     * @param int $attempt Zero-indexed retry attempt
     *
     * @return float Seconds to wait
     */
    private function retryAfterSeconds(?string $retryAfter, int $attempt): float
    {
        if ($retryAfter !== null && is_numeric($retryAfter) === true) {
            $seconds = (float) $retryAfter;
            // A literal 0 is a legitimate "retry now"; a negative or non-finite
            // value is a malformed header and falls through to the ladder.
            if ($seconds >= 0.0 && is_finite($seconds) && $seconds <= self::MAX_BACKOFF_SEC) {
                return $seconds;
            }
        }

        // Exponential backoff: 2s, 4s, 8s ...
        return self::DEFAULT_BACKOFF_SEC * (2 ** $attempt);
    }
}
