<?php

/**
 * AniList API Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Metadata\AniList;

use Phlix\Plugins\Metadata\AniList\AniListApi;
use Phlix\Plugins\Metadata\AniList\AniListApiException;
use Phlix\Plugins\Metadata\AniList\HttpClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AniListApiTest extends TestCase
{
    private const ACCESS_TOKEN = 'test-access-token';

    public function testSearchAnimeReturnsResults(): void
    {
        $expectedMedia = [
            [
                'id' => 1,
                'title' => ['romaji' => 'Cowboy Bebop', 'english' => 'Cowboy Bebop'],
                'type' => 'ANIME',
                'format' => 'TV',
                'episodes' => 26,
                'averageScore' => 85,
            ],
        ];

        $http = new MockHttpClient([
            ['data' => ['Page' => ['media' => $expectedMedia]]]],
        );
        $api = new AniListApi($http, self::ACCESS_TOKEN, new NullLogger());

        $result = $api->searchAnime('Cowboy Bebop');

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['id']);
        $this->assertSame('Cowboy Bebop', $result[0]['title']['romaji']);
    }

    public function testSearchAnimeIncludesAuthHeader(): void
    {
        $http = new MockHttpClient([['data' => ['Page' => ['media' => []]]]]);
        $api = new AniListApi($http, self::ACCESS_TOKEN, new NullLogger());

        $api->searchAnime('Test');

        $this->assertSame('Bearer test-access-token', $http->lastHeaders['Authorization'] ?? null);
    }

    public function testSearchAnimeWithoutTokenSucceeds(): void
    {
        $http = new MockHttpClient([['data' => ['Page' => ['media' => []]]]]);
        $api = new AniListApi($http, '', new NullLogger());

        $api->searchAnime('Test');

        $this->assertArrayNotHasKey('Authorization', $http->lastHeaders);
    }

    public function testSearchMangaReturnsResults(): void
    {
        $expectedMedia = [
            [
                'id' => 2,
                'title' => ['romaji' => 'Berserk', 'english' => 'Berserk'],
                'type' => 'MANGA',
                'format' => 'MANGA',
                'chapters' => 370,
                'averageScore' => 90,
            ],
        ];

        $http = new MockHttpClient([
            ['data' => ['Page' => ['media' => $expectedMedia]]]],
        );
        $api = new AniListApi($http, self::ACCESS_TOKEN, new NullLogger());

        $result = $api->searchManga('Berserk');

        $this->assertCount(1, $result);
        $this->assertSame(2, $result[0]['id']);
        $this->assertSame(370, $result[0]['chapters']);
    }

    public function testGetMediaByIdReturnsEntry(): void
    {
        $expectedEntry = [
            'id' => 1,
            'title' => ['romaji' => 'Cowboy Bebop'],
            'type' => 'ANIME',
            'episodes' => 26,
        ];

        $http = new MockHttpClient([
            ['data' => ['Media' => $expectedEntry]]],
        );
        $api = new AniListApi($http, self::ACCESS_TOKEN, new NullLogger());

        $result = $api->getMediaById(1);

        $this->assertNotNull($result);
        $this->assertSame(1, $result['id']);
        $this->assertSame('Cowboy Bebop', $result['title']['romaji']);
    }

    public function testGetMediaByIdReturnsNullWhenNotFound(): void
    {
        $http = new MockHttpClient([['data' => ['Media' => null]]]);
        $api = new AniListApi($http, self::ACCESS_TOKEN, new NullLogger());

        $result = $api->getMediaById(999999);

        $this->assertNull($result);
    }

    public function testGetMediaByMalIdReturnsEntry(): void
    {
        $expectedEntry = [
            'id' => 1,
            'idMal' => 1,
            'title' => ['romaji' => 'Cowboy Bebop'],
            'type' => 'ANIME',
        ];

        $http = new MockHttpClient([
            ['data' => ['Media' => $expectedEntry]]],
        );
        $api = new AniListApi($http, self::ACCESS_TOKEN, new NullLogger());

        $result = $api->getMediaByMalId(1);

        $this->assertNotNull($result);
        $this->assertSame(1, $result['idMal']);
    }

    public function testGetUserAnimeListReturnsEntries(): void
    {
        $expectedList = [
            [
                'id' => 1,
                'status' => 'CURRENT',
                'score' => 85,
                'progress' => 5,
                'media' => [
                    'id' => 1,
                    'idMal' => 1,
                    'title' => ['romaji' => 'Cowboy Bebop'],
                    'type' => 'ANIME',
                ],
            ],
        ];

        $http = new MockHttpClient([
            ['data' => ['Page' => ['mediaList' => $expectedList]]]],
        );
        $api = new AniListApi($http, self::ACCESS_TOKEN, new NullLogger());

        $result = $api->getUserAnimeList('testuser', 'CURRENT');

        $this->assertCount(1, $result);
        $this->assertSame('CURRENT', $result[0]['status']);
        $this->assertSame(5, $result[0]['progress']);
    }

    public function testSaveEpisodeProgress(): void
    {
        $expectedResponse = [
            'id' => 1,
            'mediaId' => 1,
            'status' => 'CURRENT',
            'score' => 0,
            'progress' => 5,
        ];

        $http = new MockHttpClient([
            ['data' => ['SaveMediaListEntry' => $expectedResponse]]],
        );
        $api = new AniListApi($http, self::ACCESS_TOKEN, new NullLogger());

        $result = $api->saveEpisodeProgress(1, 5);

        $this->assertSame(5, $result['progress']);
        $this->assertSame('CURRENT', $result['status']);
    }

    public function testGraphQLErrorsThrowException(): void
    {
        $http = new MockHttpClient([[
            'errors' => [
                ['message' => 'Variable "$id" of required type "Int!" was not provided'],
            ],
        ]]);
        $api = new AniListApi($http, self::ACCESS_TOKEN, new NullLogger());

        $this->expectException(AniListApiException::class);
        $api->getMediaById(1);
    }

    public function testQuerySendsCorrectHeaders(): void
    {
        $http = new MockHttpClient([['data' => ['Page' => ['media' => []]]]]);
        $api = new AniListApi($http, self::ACCESS_TOKEN, new NullLogger());

        $api->searchAnime('Test');

        $this->assertSame('application/json', $http->lastHeaders['Content-Type'] ?? null);
        $this->assertSame('application/json', $http->lastHeaders['Accept'] ?? null);
        $this->assertSame('Bearer test-access-token', $http->lastHeaders['Authorization'] ?? null);
    }
}

/**
 * Minimal mock HTTP client for testing AniListApi without real network calls.
 */
final class MockHttpClient implements HttpClientInterface
{
    public string $lastMethod = '';
    public string $lastUrl = '';
    public array $lastData = [];
    public array $lastHeaders = [];

    /** @var array<array> */
    private array $responses;
    private int $responseIndex = 0;

    /**
     * @param array<array> $responses Queue of responses to return
     */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    public function get(string $url, array $params = [], array $headers = []): array
    {
        $this->lastMethod = 'GET';
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;

        if (!empty($params)) {
            $this->lastUrl .= '?' . http_build_query($params);
        }

        return $this->getNextResponse();
    }

    public function post(string $url, array $data, array $headers = []): array
    {
        $this->lastMethod = 'POST';
        $this->lastUrl = $url;
        $this->lastData = $data;
        $this->lastHeaders = $headers;

        return $this->getNextResponse();
    }

    private function getNextResponse(): array
    {
        if ($this->responseIndex >= count($this->responses)) {
            return [];
        }

        return $this->responses[$this->responseIndex++];
    }
}
