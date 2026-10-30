<?php

/**
 * W-min7 minors consequence tests (docs truth + remote-field boundary parsing).
 *
 * Covers M-ANILIST-01 (phantom `@since 0.14.0` era sweep), 02 (CHANGELOG.md
 * exists), 03 (floating docblock removed), 04 (dead `$type` parameter),
 * 08 (status list truth), 10/13 (remote values parsed at the boundary before
 * metadata_json), and 12 (not-found maps to null, honoring the documented
 * contract instead of throwing).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Metadata\AniList;

use Phlix\Plugins\Metadata\AniList\AniListApi;
use Phlix\Plugins\Metadata\AniList\AniListApiException;
use Phlix\Plugins\Metadata\AniList\AniListPlugin;
use Phlix\Plugins\Metadata\AniList\HttpClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AniListMinorsW7Test extends TestCase
{
    /**
     * @param mixed ...$args
     */
    private function invokePrivate(object $target, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod($target, $method);
        $ref->setAccessible(true);

        return $ref->invoke($target, ...$args);
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function buildMetadata(array $entry): array
    {
        $ref = new \ReflectionClass(AniListPlugin::class);
        /** @var AniListPlugin $plugin */
        $plugin = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('logger');
        $prop->setAccessible(true);
        $prop->setValue($plugin, new NullLogger());

        /** @var array<string, mixed> */
        return $this->invokePrivate($plugin, 'buildMetadataFromEntry', $entry);
    }

    // ---------------------------------------------------------------
    // M-ANILIST-01 — phantom era tags
    // ---------------------------------------------------------------

    public function testNoPhantomSince0140TagsRemainInSrc(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/src/*.php');
        $this->assertIsArray($files);
        $this->assertNotCount(0, $files);

        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            $this->assertStringNotContainsString(
                '@since 0.14.0',
                $src,
                basename($file) . ' still carries a phantom @since 0.14.0 tag; this plugin has only ever been 0.1.0/0.2.0 tags and 0.3.x/0.4.x manifest versions'
            );
        }
    }

    // ---------------------------------------------------------------
    // M-ANILIST-02 — CHANGELOG.md exists
    // ---------------------------------------------------------------

    public function testTheChangelogExistsAndNamesThePublishedTags(): void
    {
        $path = dirname(__DIR__, 2) . '/CHANGELOG.md';
        $this->assertFileExists($path);

        $src = (string) file_get_contents($path);
        $this->assertStringContainsString('v0.1.0', $src);
        $this->assertStringContainsString('v0.2.0', $src);
    }

    // ---------------------------------------------------------------
    // M-ANILIST-03 — floating docblock
    // ---------------------------------------------------------------

    public function testTheFloatingContainerDocblockIsGone(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/AniListPlugin.php');

        $this->assertStringNotContainsString(
            'Schedule periodic watchlist sync.',
            $src,
            'the floating "Schedule periodic watchlist sync" block documented a container parameter schedulePeriodicSync() never had'
        );
    }

    // ---------------------------------------------------------------
    // M-ANILIST-04 — dead $type parameter
    // ---------------------------------------------------------------

    public function testLookupAniListEntryTakesOnlyTheExternalId(): void
    {
        $ref = new \ReflectionMethod(AniListPlugin::class, 'lookupAniListEntry');

        $this->assertSame(1, $ref->getNumberOfParameters(), 'the $type parameter was never read by the body');
    }

    // ---------------------------------------------------------------
    // M-ANILIST-08 — five synced statuses, not three
    // ---------------------------------------------------------------

    public function testTheReadmeAndClassDocsListTheFiveSyncedStatuses(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
        $this->assertStringNotContainsString('watching/watched/plantowatch', $readme);
        $this->assertStringContainsString('CURRENT/COMPLETED/PLANNING/PAUSED/DROPPED', $readme);

        $apiSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/src/AniListApi.php');
        $this->assertStringNotContainsString('Watching/watched/plantowatch', $apiSrc);

        $pluginSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/src/AniListPlugin.php');
        $this->assertStringNotContainsString('(watching/watched/plantowatch)', $pluginSrc);
    }

    // ---------------------------------------------------------------
    // M-ANILIST-10 / M-ANILIST-13 — remote values parsed at the boundary
    // ---------------------------------------------------------------

    public function testGenresAreFilteredElementWiseToStringsOnly(): void
    {
        $meta = $this->buildMetadata([
            'id' => 1,
            'genres' => ['Action', 42, null, new \stdClass(), 'Sci-Fi'],
        ]);

        $this->assertSame(['Action', 'Sci-Fi'], $meta['genres']);
    }

    public function testNonArrayGenresBecomeEmptyList(): void
    {
        $meta = $this->buildMetadata(['id' => 1, 'genres' => 'not-a-list']);

        $this->assertSame([], $meta['genres']);
    }

    public function testWellFormedDatesPassThroughNormalized(): void
    {
        $meta = $this->buildMetadata([
            'id' => 1,
            'startDate' => ['year' => 2024, 'month' => 1, 'day' => 1],
            'endDate' => ['year' => 2024, 'month' => 3, 'day' => 31],
        ]);

        $this->assertSame(['year' => 2024, 'month' => 1, 'day' => 1], $meta['start_date']);
        $this->assertSame(['year' => 2024, 'month' => 3, 'day' => 31], $meta['end_date']);
    }

    public function testPartialDatesKeepNullComponentsAndFullyNullDatesAreDropped(): void
    {
        $meta = $this->buildMetadata([
            'id' => 1,
            'startDate' => ['year' => 2024],
            'endDate' => ['year' => null, 'month' => null, 'day' => null],
        ]);

        $this->assertSame(['year' => 2024, 'month' => null, 'day' => null], $meta['start_date']);
        $this->assertNull($meta['end_date']);
    }

    public function testMalformedDateShapesAreRejectedToNull(): void
    {
        $meta = $this->buildMetadata([
            'id' => 1,
            'startDate' => '2024-01-01',
            'endDate' => ['year' => 'twenty-twenty-four', 'month' => 1, 'day' => 1],
        ]);

        $this->assertNull($meta['start_date']);
        $this->assertNull($meta['end_date']);
    }

    public function testNextAiringEpisodeShapeIsVerified(): void
    {
        $meta = $this->buildMetadata([
            'id' => 1,
            'nextAiringEpisode' => ['episode' => 5, 'airingAt' => 1700000000],
        ]);
        $this->assertSame(['episode' => 5, 'airingAt' => 1700000000], $meta['next_airing_episode']);

        $meta = $this->buildMetadata([
            'id' => 1,
            'nextAiringEpisode' => ['episode' => 'five', 'airingAt' => 1700000000],
        ]);
        $this->assertNull($meta['next_airing_episode']);

        $meta = $this->buildMetadata(['id' => 1, 'nextAiringEpisode' => 'soon']);
        $this->assertNull($meta['next_airing_episode']);
    }

    public function testControlAndBidiCharactersAreScrubbedFromStoredTitles(): void
    {
        $meta = $this->buildMetadata([
            'id' => 1,
            'title' => [
                'romaji' => "Cowboy\x00 Bebop",
                'english' => "Cowboy‮ Bebop",
                'native' => "Cowboy\u{2066}Bebop\u{2069}",
            ],
        ]);

        $this->assertSame('Cowboy Bebop', $meta['title_romaji']);
        $this->assertSame('Cowboy Bebop', $meta['title_english']);
        $this->assertSame('CowboyBebop', $meta['title_native']);
    }

    public function testCoverAndBannerUrlsAreConstrainedToHttps(): void
    {
        $meta = $this->buildMetadata([
            'id' => 1,
            'coverImage' => [
                'large' => 'http://example.com/insecure.jpg',
                'extraLarge' => 'https://example.com/secure.jpg',
            ],
            'bannerImage' => 'javascript:alert(1)',
        ]);

        $this->assertNull($meta['cover_image_large']);
        $this->assertSame('https://example.com/secure.jpg', $meta['cover_image_extralarge']);
        $this->assertNull($meta['banner_image']);
    }

    // ---------------------------------------------------------------
    // M-ANILIST-12 — not-found honors the null contract
    // ---------------------------------------------------------------

    public function testGetMediaByMalIdMapsGraphqlNotFoundToNull(): void
    {
        $http = new MockHttpClient([[
            'errors' => [['message' => 'Not found', 'status' => 404]],
        ]]);
        $api = new AniListApi($http, 'test-access-token', new NullLogger());

        $this->assertNull($api->getMediaByMalId(999999));
    }

    public function testGetMediaByIdMapsTransportHttp404ToNull(): void
    {
        $http = new class implements HttpClientInterface {
            public function post(string $url, array $data, array $headers = []): array
            {
                throw new AniListApiException('AniList API returned HTTP 404: {"errors":[{"message":"Not found"}]}');
            }

            public function get(string $url, array $params = [], array $headers = []): array
            {
                throw new AniListApiException('unused');
            }
        };
        $api = new AniListApi($http, 'test-access-token', new NullLogger());

        $this->assertNull($api->getMediaById(1));
    }

    public function testRealApiErrorsStillThrowAfterTheNotFoundMapping(): void
    {
        $http = new MockHttpClient([[
            'errors' => [['message' => 'Internal server error']],
        ]]);
        $api = new AniListApi($http, 'test-access-token', new NullLogger());

        $this->expectException(AniListApiException::class);
        $api->getMediaById(1);
    }
}
