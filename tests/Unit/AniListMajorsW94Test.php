<?php

/**
 * Majors-space consequence tests — review campaign W94 batch.
 *
 * ERA NOTE: the `M-ANILIST-NN` literals in this file are MAJORS-space queue
 * IDs (`.reviews/MAJORS_QUEUE.md`, repo anilist, batch W94). They collide on
 * purpose with the CLOSED minors-space items of the same numbers (and with
 * the minors batch tests AniListMinorsW7/8/9Test.php, whose "M-ANILIST-NN"
 * references mean the MINORS queue). The spaces are distinct item families;
 * this file documents only the majors cures.
 *
 * Covered here so far:
 * - M-ANILIST-04 (majors): a remote 4xx/5xx body may ride into an exception
 *   message — and from there verbatim into logs — only as a control-char
 *   stripped, newline-free, length-bounded excerpt.
 * - M-ANILIST-05 (majors): watchlist status fetching walks every page under
 *   a stable sort and a loud capped backstop, not just page 1 x 50.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Metadata\AniList;

use Phlix\Plugins\Metadata\AniList\AniListApi;
use Phlix\Plugins\Metadata\AniList\AniListApiException;
use Phlix\Plugins\Metadata\AniList\AniListPlugin;
use Phlix\Plugins\Metadata\AniList\AniListSettings;
use Phlix\Plugins\Metadata\AniList\HttpClient;
use Phlix\Plugins\Metadata\AniList\HttpClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AniListMajorsW94Test extends TestCase
{
    /**
     * Build an HttpClient whose transport is pinned to one canned response.
     *
     * @param array{status: int, body: string, retry_after?: string|null} $response
     */
    private function clientReturning(array $response): HttpClient
    {
        return new class ($response) extends HttpClient {
            public function __construct(private array $canned)
            {
                parent::__construct(null);
            }

            protected function sendOnce(string $method, string $url, array $data, array $headers): array
            {
                return [
                    'status' => $this->canned['status'],
                    'body' => $this->canned['body'],
                    'retry_after' => $this->canned['retry_after'] ?? null,
                ];
            }

            protected function tick(): void
            {
            }
        };
    }

    public function testRemoteErrorBodyControlCharsAndNewlinesNeverReachTheMessage(): void
    {
        $body = "ERR\n\x1b[31mred alert\x00 next\x{202E}spoof\r\nforged entry";

        $client = $this->clientReturning(['status' => 400, 'body' => $body]);

        try {
            $client->post('https://graphql.anilist.co', []);
            $this->fail('expected AniListApiException for HTTP 400');
        } catch (AniListApiException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringStartsWith('AniList API returned HTTP 400: ', $message);
        $this->assertStringNotContainsString("\n", $message, 'newline in a logged message forges log entries');
        $this->assertStringNotContainsString("\r", $message);
        $this->assertStringNotContainsString("\x1b", $message, 'ANSI escapes poison terminal log viewers');
        $this->assertStringNotContainsString("\x00", $message);
        $this->hasNoControlCharacters($message);
        // The printable content survives, collapsed onto one line.
        $this->assertStringContainsString('red alert', $message);
        $this->assertStringContainsString('forged entry', $message);
    }

    public function testRemoteErrorBodyIsLengthBoundedInTheMessage(): void
    {
        $body = str_repeat('A', 5_000);

        $client = $this->clientReturning(['status' => 500, 'body' => $body]);

        try {
            $client->post('https://graphql.anilist.co', []);
            $this->fail('expected AniListApiException for HTTP 500');
        } catch (AniListApiException $e) {
            $message = $e->getMessage();
        }

        $prefix = 'AniList API returned HTTP 500: ';
        $this->assertStringStartsWith($prefix, $message);
        $this->assertLessThanOrEqual(
            strlen($prefix) + 500 + strlen('[truncated]'),
            strlen($message),
            'the excerpt must respect the 500-character ceiling'
        );
        $this->assertStringEndsWith('[truncated]', $message, 'truncation must be visible');
    }

    public function testUnprintableOnlyBodyYieldsPlaceholderNotRawBytes(): void
    {
        $client = $this->clientReturning(['status' => 422, 'body' => "\x01\x02\n\r  "]);

        try {
            $client->post('https://graphql.anilist.co', []);
            $this->fail('expected AniListApiException for HTTP 422');
        } catch (AniListApiException $e) {
            $message = $e->getMessage();
        }

        $this->assertSame('AniList API returned HTTP 422: (no printable body)', $message);
    }

    public function testInvalidUtf8BodyFallsBackToPrintableAscii(): void
    {
        $body = "\xff\xfe garbage \x80\x81 payload";

        $client = $this->clientReturning(['status' => 400, 'body' => $body]);

        try {
            $client->post('https://graphql.anilist.co', []);
            $this->fail('expected AniListApiException for HTTP 400');
        } catch (AniListApiException $e) {
            $message = $e->getMessage();
        }

        $excerpt = substr($message, strlen('AniList API returned HTTP 400: '));
        $this->assertMatchesRegularExpression('/^[\x20-\x7E]*$/', $excerpt);
        $this->assertStringContainsString('garbage', $message);
    }

    public function testHttpStatusPrefixSurvivesForTheNotFoundContract(): void
    {
        // AniListApi::isNotFoundFailure() keys the 404-to-null mapping on the
        // literal 'HTTP 404'; the sanitizer must never eat the prefix.
        $client = $this->clientReturning(['status' => 404, 'body' => '{"errors":[{"message":"Not found"}]}']);

        try {
            $client->post('https://graphql.anilist.co', []);
            $this->fail('expected AniListApiException for HTTP 404');
        } catch (AniListApiException $e) {
            $this->assertStringContainsString('HTTP 404', $e->getMessage());
        }
    }

    // ------------------------------------------------ M-ANILIST-05 (majors) --

    /**
     * Build a plugin whose status fetch is served by canned pages.
     *
     * @param array<int, list<array<string, mixed>>> $pages page number => entries
     * @return array{0: AniListPlugin, 1: object{pagesRequested: list<int>}}
     */
    private function pluginWithPages(array $pages, ?RecordingAniListLogger $logger = null): array
    {
        $http = new class ($pages) implements HttpClientInterface {
            /** @var list<int> */
            public array $pagesRequested = [];

            /** @var list<string> */
            public array $queries = [];

            /**
             * @param array<int, list<array<string, mixed>>> $pages
             */
            public function __construct(private array $pages)
            {
            }

            public function post(string $url, array $data, array $headers = []): array
            {
                $page = (int) ($data['variables']['page'] ?? 0);
                $this->pagesRequested[] = $page;
                $this->queries[] = (string) ($data['query'] ?? '');

                return ['data' => ['Page' => ['mediaList' => $this->pages[$page] ?? []]]];
            }

            public function get(string $url, array $params = [], array $headers = []): array
            {
                return [];
            }
        };

        $settings = new AniListSettings(
            accessToken: 'token',
            username: 'testuser',
            syncEnabled: true,
        );
        $api = new AniListApi($http, 'token', new NullLogger());
        $ref = new \ReflectionClass(AniListPlugin::class);
        /** @var AniListPlugin $plugin */
        $plugin = $ref->newInstanceWithoutConstructor();
        $p = new \ReflectionProperty($plugin, 'settings');
        $p->setAccessible(true);
        $p->setValue($plugin, $settings);
        $p = new \ReflectionProperty($plugin, 'enabled');
        $p->setAccessible(true);
        $p->setValue($plugin, true);
        $p = new \ReflectionProperty($plugin, 'api');
        $p->setAccessible(true);
        $p->setValue($plugin, $api);
        $p = new \ReflectionProperty($plugin, 'logger');
        $p->setAccessible(true);
        $p->setValue($plugin, $logger ?? new NullLogger());

        return [$plugin, $http];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fullPage(int $perPage): array
    {
        $entries = [];
        for ($i = 0; $i < $perPage; $i++) {
            $entries[] = ['media' => ['id' => $i, 'idMal' => 1000 + $i]];
        }

        return $entries;
    }

    public function testSyncStatusListWalksEveryPageUntilAShortPage(): void
    {
        // 50 entries on page 1 (= perPage, more may follow) and 2 on page 2
        // (short page = end of list). The fetch must ask for exactly [1, 2].
        [$plugin, $http] = $this->pluginWithPages([1 => $this->fullPage(50), 2 => $this->fullPage(2)]);

        $m = new \ReflectionMethod($plugin, 'syncStatusList');
        $m->setAccessible(true);
        $m->invoke($plugin, 'testuser', 'CURRENT');

        $this->assertSame([1, 2], $http->pagesRequested, 'a full page must be followed by the next page');
    }

    public function testSyncStatusListStopsAfterTheFirstEmptyPage(): void
    {
        [$plugin, $http] = $this->pluginWithPages([1 => $this->fullPage(50), 2 => $this->fullPage(50), 3 => []]);

        $m = new \ReflectionMethod($plugin, 'syncStatusList');
        $m->setAccessible(true);
        $m->invoke($plugin, 'testuser', 'CURRENT');

        $this->assertSame([1, 2, 3], $http->pagesRequested);
    }

    public function testSyncStatusListCapsPaginationAndWarnsLoudly(): void
    {
        $ref = new \ReflectionClass(AniListPlugin::class);
        $cap = (int) $ref->getConstant('MAX_SYNC_PAGES');
        $this->assertGreaterThan(0, $cap);

        $pages = [];
        for ($p = 1; $p <= $cap + 1; $p++) {
            $pages[$p] = $this->fullPage(50);
        }

        $logger = new RecordingAniListLogger();
        [$plugin, $http] = $this->pluginWithPages($pages, $logger);

        $m = new \ReflectionMethod($plugin, 'syncStatusList');
        $m->setAccessible(true);
        $m->invoke($plugin, 'testuser', 'CURRENT');

        $this->assertCount($cap, $http->pagesRequested, 'pagination must stop at the documented cap');
        $this->assertTrue(
            $logger->hasRecord('warning', 'pagination cap'),
            'hitting the cap must be a loud, visible truncation — never a silent one'
        );
    }

    public function testAnimeListQueryCarriesAStableSortForPaging(): void
    {
        // Unstable ordering across pages silently duplicates or drops entries
        // while walking; the walk is only sound with a fixed order.
        [$plugin, $http] = $this->pluginWithPages([1 => []]);

        $m = new \ReflectionMethod($plugin, 'syncStatusList');
        $m->setAccessible(true);
        $m->invoke($plugin, 'testuser', 'CURRENT');

        $this->assertCount(1, $http->queries);
        $this->assertStringContainsString('mediaList(userName: $userName', $http->queries[0]);
        $this->assertStringContainsString('sort: [MEDIA_ID]', $http->queries[0]);
    }

    /**
     * Assert the message carries no C0/C1 control characters at all
     * (tab/LF/CR included — this is a one-line log payload).
     */
    private function hasNoControlCharacters(string $message): void
    {
        $this->assertSame(
            0,
            preg_match('/[\x00-\x1F\x7F\x{0080}-\x{009F}]/u', $message) ?: 0,
            'exception message must be control-character free'
        );
    }
}
