<?php

/**
 * AniList HTTP Client Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Metadata\AniList;

use Phlix\Plugins\Metadata\AniList\HttpClient;
use Phlix\Plugins\Metadata\AniList\HttpClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for HttpClient, specifically the get() method and the request() method's
 * handling of HTTP errors.
 */
final class AniListHttpClientTest extends TestCase
{
    public function testGetAppendsQueryStringToUrl(): void
    {
        $client = new class (new NullLogger()) extends HttpClient {
            /** @var list<array{method: string, url: string}> */
            public array $capturedCalls = [];

            protected function sendOnce(string $method, string $url, array $data, array $headers): array
            {
                $this->capturedCalls[] = ['method' => $method, 'url' => $url];

                return [
                    'status' => 200,
                    'body' => '{"data":{"ok":true}}',
                    'retry_after' => null,
                ];
            }
        };

        $client->get('https://example.com/api', ['foo' => 'bar', 'baz' => 'qux']);

        $this->assertCount(1, $client->capturedCalls);
        $this->assertSame('GET', $client->capturedCalls[0]['method']);
        $this->assertStringContainsString('foo=bar', $client->capturedCalls[0]['url']);
        $this->assertStringContainsString('baz=qux', $client->capturedCalls[0]['url']);
    }

    public function testGetWithoutParamsDoesNotAppendQuestionMark(): void
    {
        $client = new class (new NullLogger()) extends HttpClient {
            /** @var list<array{method: string, url: string}> */
            public array $capturedCalls = [];

            protected function sendOnce(string $method, string $url, array $data, array $headers): array
            {
                $this->capturedCalls[] = ['method' => $method, 'url' => $url];

                return [
                    'status' => 200,
                    'body' => '{"data":{"ok":true}}',
                    'retry_after' => null,
                ];
            }
        };

        $client->get('https://example.com/api');

        $this->assertCount(1, $client->capturedCalls);
        $this->assertSame('GET', $client->capturedCalls[0]['method']);
        $this->assertStringNotContainsString('?', $client->capturedCalls[0]['url']);
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

        $this->expectException(\Phlix\Plugins\Metadata\AniList\AniListApiException::class);
        $this->expectExceptionMessage('AniList API returned HTTP 400: Bad Request');

        $client->post('https://graphql.anilist.co', ['query' => 'test']);
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

        $this->expectException(\Phlix\Plugins\Metadata\AniList\AniListApiException::class);
        $this->expectExceptionMessage('AniList API returned HTTP 500: Internal Server Error');

        $client->post('https://graphql.anilist.co', ['query' => 'test']);
    }

    public function testRequestThrowsOnInvalidJson(): void
    {
        $client = new class (new NullLogger()) extends HttpClient {
            protected function sendOnce(string $method, string $url, array $data, array $headers): array
            {
                return [
                    'status' => 200,
                    'body' => 'not valid json {{{',
                    'retry_after' => null,
                ];
            }
        };

        $this->expectException(\Phlix\Plugins\Metadata\AniList\AniListApiException::class);
        $this->expectExceptionMessage('Invalid JSON response from AniList API');

        $client->post('https://graphql.anilist.co', ['query' => 'test']);
    }

    public function testRequestThrowsWhenQueueExhausted(): void
    {
        $client = new class (new NullLogger()) extends HttpClient {
            protected function sendOnce(string $method, string $url, array $data, array $headers): array
            {
                return [
                    'status' => 500,
                    'body' => '',
                    'retry_after' => null,
                ];
            }
        };

        $this->expectException(\Phlix\Plugins\Metadata\AniList\AniListApiException::class);

        $client->post('https://graphql.anilist.co', ['query' => 'test']);
    }
}
