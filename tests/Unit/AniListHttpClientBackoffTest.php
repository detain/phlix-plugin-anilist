<?php

/**
 * AniList HTTP client 429-backoff + Bearer-token consequence tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Metadata\AniList;

use Phlix\Plugins\Metadata\AniList\AniListApi;
use Phlix\Plugins\Metadata\AniList\AniListApiException;
use Phlix\Plugins\Metadata\AniList\HttpClient;
use Phlix\Plugins\Metadata\AniList\HttpClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The transport now retries HTTP 429 with a cooperative backoff that honours
 * Retry-After. These tests drive the retry loop through a test double so no
 * real network or real sleep is involved.
 */
final class AniListHttpClientBackoffTest extends TestCase
{
    public function test_429_then_success_retries_and_honours_retry_after(): void
    {
        $client = new BackoffProbeHttpClient([
            ['status' => 429, 'body' => '', 'retry_after' => '2'],
            ['status' => 200, 'body' => '{"data":{"ok":1}}', 'retry_after' => null],
        ]);

        $result = $client->post('https://graphql.anilist.co', ['query' => 'x']);

        $this->assertSame(['data' => ['ok' => 1]], $result);
        $this->assertSame(2, $client->sends, 'must send twice (initial + one retry)');
        $this->assertSame([2.0], $client->sleeps, 'must back off for the Retry-After delay');
    }

    public function test_429_without_retry_after_uses_exponential_backoff(): void
    {
        $client = new BackoffProbeHttpClient([
            ['status' => 429, 'body' => '', 'retry_after' => null],
            ['status' => 429, 'body' => '', 'retry_after' => null],
            ['status' => 200, 'body' => '{"data":{}}', 'retry_after' => null],
        ]);

        $client->post('https://graphql.anilist.co', ['query' => 'x']);

        // DEFAULT_BACKOFF_SEC (2) * 2^attempt => 2, 4
        $this->assertSame([2.0, 4.0], $client->sleeps);
        $this->assertSame(3, $client->sends);
    }

    public function test_persistent_429_throws_after_retries_are_exhausted(): void
    {
        $client = new BackoffProbeHttpClient([
            ['status' => 429, 'body' => '', 'retry_after' => '1'],
            ['status' => 429, 'body' => '', 'retry_after' => '1'],
            ['status' => 429, 'body' => '', 'retry_after' => '1'],
            ['status' => 429, 'body' => '', 'retry_after' => '1'],
        ]);

        $this->expectException(AniListApiException::class);

        try {
            $client->post('https://graphql.anilist.co', ['query' => 'x']);
        } finally {
            // 3 backoffs for MAX_RETRIES=3, then a throw on the 4th 429.
            $this->assertSame([1.0, 1.0, 1.0], $client->sleeps);
        }
    }

    public function test_manual_token_is_sent_as_bearer(): void
    {
        $http = new HeaderCapturingHttpClient(['data' => ['Page' => ['media' => []]]]);
        $api = new AniListApi($http, 'my-manual-personal-access-token', new NullLogger());

        $api->searchAnime('anything');

        $this->assertSame(
            'Bearer my-manual-personal-access-token',
            $http->lastHeaders['Authorization'] ?? null
        );
    }
}

/**
 * HttpClient test double: replaces the real Workerman transport with a queue of
 * canned raw responses and records the backoff intervals instead of sleeping.
 */
final class BackoffProbeHttpClient extends HttpClient
{
    /** @var list<float> */
    public array $sleeps = [];

    public int $sends = 0;

    /** @var list<array{status: int, body: string, retry_after: string|null}> */
    private array $queue;

    /**
     * @param list<array{status: int, body: string, retry_after: string|null}> $queue
     */
    public function __construct(array $queue)
    {
        parent::__construct(new NullLogger());
        $this->queue = $queue;
    }

    protected function sendOnce(string $method, string $url, array $data, array $headers): array
    {
        $this->sends++;
        $next = array_shift($this->queue);

        return $next ?? ['status' => 500, 'body' => '', 'retry_after' => null];
    }

    protected function backoffSleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
    }
}

/**
 * Captures the last request headers so the Bearer token can be asserted.
 */
final class HeaderCapturingHttpClient implements HttpClientInterface
{
    /** @var array<string, string> */
    public array $lastHeaders = [];

    /** @param array<string, mixed> $response */
    public function __construct(private array $response)
    {
    }

    public function post(string $url, array $data, array $headers = []): array
    {
        $this->lastHeaders = $headers;

        return $this->response;
    }

    public function get(string $url, array $params = [], array $headers = []): array
    {
        $this->lastHeaders = $headers;

        return $this->response;
    }
}
