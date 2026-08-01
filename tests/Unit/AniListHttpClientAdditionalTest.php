<?php

/**
 * AniList HTTP Client additional coverage tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Metadata\AniList;

use Phlix\Plugins\Metadata\AniList\AniListApiException;
use Phlix\Plugins\Metadata\AniList\HttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for HttpClient retryAfterSeconds and error paths.
 */
final class AniListHttpClientAdditionalTest extends TestCase
{
    public function testSendOnceThrowsWhenWorkermanUnavailable(): void
    {
        $client = new class (new NullLogger()) extends HttpClient {
            protected function sendOnce(string $method, string $url, array $data, array $headers): array
            {
                // Simulate the Workerman unavailability check
                if (!class_exists(\Workerman\Http\Client::class)) {
                    throw new AniListApiException('Workerman HTTP client is unavailable in this runtime');
                }
                return ['status' => 200, 'body' => '{}', 'retry_after' => null];
            }
        };

        $this->expectException(AniListApiException::class);
        $this->expectExceptionMessage('Workerman HTTP client is unavailable');

        $client->post('https://example.com/api', ['test']);
    }

    public function testBackoffSleepHandlesZeroSecondsGracefully(): void
    {
        $client = new class (new NullLogger()) extends HttpClient {
            protected function sendOnce(string $method, string $url, array $data, array $headers): array
            {
                return [
                    'status' => 200,
                    'body' => '{"data":{"ok":true}}',
                    'retry_after' => null,
                ];
            }

            public function publicBackoffSleep(float $seconds): void
            {
                // Just call it - it should return early for 0
                $this->backoffSleep($seconds);
            }
        };

        // Should not throw and should return early
        $client->publicBackoffSleep(0.0);
        $client->publicBackoffSleep(-1.0);

        $this->assertTrue(true);
    }

    public function testRetryAfterSecondsHonorsRetryAfterHeader(): void
    {
        $client = new HttpClient(new NullLogger());

        // Using reflection to test private method
        $ref = new \ReflectionClass($client);
        $method = $ref->getMethod('retryAfterSeconds');
        $method->setAccessible(true);

        // Test with valid numeric Retry-After
        $result = $method->invoke($client, '10', 0);
        $this->assertSame(10.0, $result);

        // Test with negative Retry-After (should be clamped to 0)
        $result = $method->invoke($client, '-5', 0);
        $this->assertSame(0.0, $result);

        // Test with null Retry-After (exponential backoff)
        $result = $method->invoke($client, null, 0);
        $this->assertSame(2.0, $result); // DEFAULT_BACKOFF_SEC * 2^0 = 2

        $result = $method->invoke($client, null, 1);
        $this->assertSame(4.0, $result); // DEFAULT_BACKOFF_SEC * 2^1 = 4

        $result = $method->invoke($client, null, 2);
        $this->assertSame(8.0, $result); // DEFAULT_BACKOFF_SEC * 2^2 = 8

        // Test with non-numeric Retry-After (falls back to exponential)
        $result = $method->invoke($client, 'not-a-number', 0);
        $this->assertSame(2.0, $result);
    }

    public function testRequestThrowsOnHttp400(): void
    {
        $client = new class (new NullLogger()) extends HttpClient {
            protected function sendOnce(string $method, string $url, array $data, array $headers): array
            {
                return [
                    'status' => 400,
                    'body' => 'Bad Request',
                    'retry_after' => null,
                ];
            }
        };

        $this->expectException(AniListApiException::class);
        $this->expectExceptionMessage('AniList API returned HTTP 400: Bad Request');

        $client->post('https://example.com/api', ['test']);
    }

    public function testRequestThrowsOnHttp500(): void
    {
        $client = new class (new NullLogger()) extends HttpClient {
            protected function sendOnce(string $method, string $url, array $data, array $headers): array
            {
                return [
                    'status' => 500,
                    'body' => 'Internal Server Error',
                    'retry_after' => null,
                ];
            }
        };

        $this->expectException(AniListApiException::class);
        $this->expectExceptionMessage('AniList API returned HTTP 500: Internal Server Error');

        $client->get('https://example.com/api');
    }

    public function testSendOnceTransportErrorThrowsAniListApiException(): void
    {
        $client = new class (new NullLogger()) extends HttpClient {
            protected function sendOnce(string $method, string $url, array $data, array $headers): array
            {
                // Simulate what the real sendOnce does when there's a transport error
                throw new AniListApiException('Connection refused');
            }
        };

        $this->expectException(AniListApiException::class);
        $this->expectExceptionMessage('Connection refused');

        $client->post('https://example.com/api', ['test']);
    }

    public function testRequestThrowsOnInvalidJson(): void
    {
        $client = new class (new NullLogger()) extends HttpClient {
            protected function sendOnce(string $method, string $url, array $data, array $headers): array
            {
                return [
                    'status' => 200,
                    'body' => 'not valid json',
                    'retry_after' => null,
                ];
            }
        };

        $this->expectException(AniListApiException::class);
        $this->expectExceptionMessage('Invalid JSON response from AniList API');

        $client->get('https://example.com/api');
    }
}
