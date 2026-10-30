<?php

/**
 * AniList Minors W-min9 Batch Test.
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
use Phlix\Plugins\Metadata\AniList\HttpClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Transport recorder for the W-min9 batch: a scripted response queue with a
 * hard post-count. An exhausted queue THROWS, so assertions like "the stale
 * progress write was skipped" have teeth — an extra request is a loud failure,
 * not a silent empty answer. Queue entries are either response arrays (which
 * are returned) or Throwable instances (which are thrown from post()).
 */
final class RecordingHttpClientForW9 implements HttpClientInterface
{
    public int $postCount = 0;

    /** @var array<string, mixed> */
    public array $lastData = [];

    /** @var list<array<string, mixed>|Throwable> */
    private array $queue;

    /** @param list<array<string, mixed>|Throwable> $queue */
    public function __construct(array $queue = [])
    {
        $this->queue = $queue;
    }

    public function get(string $url, array $params = [], array $headers = []): array
    {
        return $this->next();
    }

    public function post(string $url, array $data, array $headers = []): array
    {
        $this->postCount++;
        $this->lastData = $data;

        return $this->next();
    }

    /** @return array<string, mixed> */
    private function next(): array
    {
        if ($this->queue === []) {
            throw new \RuntimeException('RecordingHttpClientForW9: unexpected extra request');
        }

        $item = array_shift($this->queue);

        if ($item instanceof \Throwable) {
            throw $item;
        }

        return $item;
    }
}

/**
 * Estate review campaign minors batch W-min9:
 * M-ANILIST-09 (unbounded Retry-After), M-ANILIST-11 (token custody),
 * M-ANILIST-14 (hardcoded anilist_favourite), M-ANILIST-15 (monotonic
 * progress + per-drain lookup memo), M-ANILIST-17 (wall-time wait deadline).
 */
final class AniListMinorsW9Test extends TestCase
{
    // ------------------------------------------------------------- 09 ----

    /**
     * A server-supplied Retry-After beyond the ceiling (misconfigured gateway,
     * stale header, adversarial answer) must NOT park the resident sync worker:
     * the wait falls back to the plugin's own exponential ladder instead.
     */
    public function testRetryAfterAboveCeilingFallsBackToLadder(): void
    {
        $client = new \Phlix\Plugins\Metadata\AniList\HttpClient(new NullLogger());
        $method = (new \ReflectionClass($client))->getMethod('retryAfterSeconds');

        $this->assertSame(2.0, $method->invoke($client, '3600', 0), 'hour-long Retry-After must fall back to 2s ladder');
        $this->assertSame(4.0, $method->invoke($client, '61', 1), 'Retry-After just above the 60s ceiling falls back');
        $this->assertSame(60.0, $method->invoke($client, '60', 0), 'Retry-After AT the ceiling is still honored');
        $this->assertSame(10.0, $method->invoke($client, '10', 0), 'in-range Retry-After is honored verbatim');
    }

    // ------------------------------------------------------------- 11 ----

    public function testAccessTokenIsNotPubliclyReadable(): void
    {
        $property = (new \ReflectionClass(AniListSettings::class))->getProperty('accessToken');

        $this->assertTrue($property->isPrivate(), 'bearer token property must be private (M-ANILIST-11)');
        $this->assertTrue(
            (new \ReflectionClass(AniListSettings::class))->hasMethod('accessToken'),
            'the token must be reachable through the explicit accessToken() accessor'
        );
    }

    public function testGeneralSerializerOmitsTokenByDefault(): void
    {
        $settings = new AniListSettings(accessToken: 's3cret', username: 'joe');

        $this->assertArrayNotHasKey('access_token', $settings->toArray());
        $this->assertSame('s3cret', $settings->toArray(true)['access_token'], 'explicit includeSecret still round-trips');
        $this->assertSame('s3cret', $settings->accessToken());
    }

    public function testTokenNeverLeaksViaObjectSerialization(): void
    {
        $settings = new AniListSettings(accessToken: 's3cret', username: 'joe');

        $this->assertStringNotContainsString('s3cret', json_encode($settings), 'json_encode of settings must not carry the token');
        $this->assertStringNotContainsString('s3cret', json_encode($settings->toSpaArray()));
    }

    public function testEverySettingsReaderInSrcUsesTheAccessor(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/AniListPlugin.php');

        $this->assertStringNotContainsString(
            '$this->settings->accessToken ',
            $src,
            'property reads of the now-private token would fatal: the accessor must be used'
        );
    }

    // ------------------------------------------------------------- 14 ----

    public function testWatchlistMetadataNoLongerHardcodesFavouriteFalse(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/AniListPlugin.php');

        $this->assertStringNotContainsString(
            "'anilist_favourite'",
            $src,
            'the watchlist write hardwrote anilist_favourite=false although the flag is never fetched (M-ANILIST-14)'
        );
    }

    // ------------------------------------------------------------- 15 ----

    public function testGetMediaListProgressReadsViewerEntry(): void
    {
        $http = new RecordingHttpClientForW9([
            ['data' => ['Media' => ['mediaListEntry' => ['progress' => 10]]]],
        ]);
        $api = new AniListApi($http, 'tok', new NullLogger());

        $this->assertSame(10, $api->getMediaListProgress(555));
        $this->assertStringContainsString('mediaListEntry', (string) ($http->lastData['query'] ?? ''), 'the viewer-scoped field verified against the live schema must be the one queried');
        $this->assertStringContainsString('progress', (string) ($http->lastData['query'] ?? ''));
    }

    public function testGetMediaListProgressNullWithoutEntry(): void
    {
        $http = new RecordingHttpClientForW9([
            ['data' => ['Media' => ['mediaListEntry' => null]]],
        ]);
        $api = new AniListApi($http, 'tok', new NullLogger());

        $this->assertNull($api->getMediaListProgress(555));
    }

    public function testGetMediaListProgressMalformedTreatedAsAbsent(): void
    {
        $http = new RecordingHttpClientForW9([
            ['data' => ['Media' => ['mediaListEntry' => ['progress' => 'garbage']]]],
        ]);
        $api = new AniListApi($http, 'tok', new NullLogger());

        $this->assertNull($api->getMediaListProgress(555));
    }

    public function testStaleProgressNeverWalksSavedProgressBackwards(): void
    {
        // Probe answers progress=10; episode 5 is OLDER, so the save mutation
        // must not be issued at all — an extra request throws in the recorder.
        $http = new RecordingHttpClientForW9([
            ['data' => ['Media' => ['mediaListEntry' => ['progress' => 10]]]],
        ]);
        $plugin = $this->makePluginWithApi($http);

        $this->invokePrivate($plugin, 'syncProgressToAniList', [555, 5]);

        $this->assertSame(1, $http->postCount, 'stale progress write must be skipped after the probe');
    }

    public function testEqualProgressIsAlsoSkipped(): void
    {
        $http = new RecordingHttpClientForW9([
            ['data' => ['Media' => ['mediaListEntry' => ['progress' => 7]]]],
        ]);
        $plugin = $this->makePluginWithApi($http);

        $this->invokePrivate($plugin, 'syncProgressToAniList', [555, 7]);

        $this->assertSame(1, $http->postCount);
    }

    public function testNewerProgressStillWrites(): void
    {
        $http = new RecordingHttpClientForW9([
            ['data' => ['Media' => ['mediaListEntry' => ['progress' => 10]]]],
            ['data' => ['SaveMediaListEntry' => ['id' => 1, 'progress' => 12]]],
        ]);
        $plugin = $this->makePluginWithApi($http);

        $this->invokePrivate($plugin, 'syncProgressToAniList', [555, 12]);

        $this->assertSame(2, $http->postCount, 'newer progress must probe, then save');
    }

    public function testUnreadableProgressDoesNotBlockTheWrite(): void
    {
        // Fail-soft: a failed probe (transport/API error) must fall through to
        // the save, preserving the old always-write behavior on uncertainty.
        $http = new RecordingHttpClientForW9([
            new AniListApiException('AniList API returned HTTP 500: boom'),
            ['data' => ['SaveMediaListEntry' => ['id' => 1, 'progress' => 3]]],
        ]);
        $plugin = $this->makePluginWithApi($http);

        $this->invokePrivate($plugin, 'syncProgressToAniList', [555, 3]);

        $this->assertSame(2, $http->postCount);
    }

    public function testEntryLookupsAreMemoizedWithinTheDrainCycle(): void
    {
        $http = new RecordingHttpClientForW9([
            ['data' => ['Media' => ['id' => 555, 'idMal' => 1]]],
        ]);
        $plugin = $this->makePluginWithApi($http);

        $first = $this->invokePrivate($plugin, 'lookupAniListEntry', [1]);
        $second = $this->invokePrivate($plugin, 'lookupAniListEntry', [1]);

        $this->assertIsArray($first);
        $this->assertSame($first, $second, 'second lookup within the cycle must serve the memo');
        $this->assertSame(1, $http->postCount, 'memoized lookup must not re-hit the network');
    }

    public function testDefinitiveMissesAreMemoizedToo(): void
    {
        // getMediaByMalId maps the "Not found" envelope to null — an absence is
        // as stable as a hit within one drain cycle.
        $http = new RecordingHttpClientForW9([
            ['errors' => [['message' => 'Not found']]],
        ]);
        $plugin = $this->makePluginWithApi($http);

        $this->assertNull($this->invokePrivate($plugin, 'lookupAniListEntry', [4242]));
        $this->assertNull($this->invokePrivate($plugin, 'lookupAniListEntry', [4242]));
        $this->assertSame(1, $http->postCount);
    }

    public function testTransientFailuresAreNotMemoized(): void
    {
        $http = new RecordingHttpClientForW9([
            new AniListApiException('AniList API returned HTTP 503: temporarily down'),
            ['data' => ['Media' => ['id' => 555, 'idMal' => 1]]],
        ]);
        $plugin = $this->makePluginWithApi($http);

        $this->assertNull($this->invokePrivate($plugin, 'lookupAniListEntry', [1]), 'failed lookup answers null for this call');
        $entry = $this->invokePrivate($plugin, 'lookupAniListEntry', [1]);
        $this->assertIsArray($entry, 'a transport failure must not poison the memo');
        $this->assertSame(2, $http->postCount);
    }

    public function testMemoResetHookAndDrainCycleClearIt(): void
    {
        $plugin = $this->makePluginWithApi(new RecordingHttpClientForW9());

        $reset = (new \ReflectionClass(AniListPlugin::class))->getMethod('resetLookupMemo');
        $reset->invoke($plugin);

        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/AniListPlugin.php');

        $this->assertMatchesRegularExpression(
            '/if \(\$this->enrichQueue === \[\]\) \{\s*\$this->resetLookupMemo\(\);/',
            $src,
            'the drain timer callback must reset the memo once the queue runs dry (cycle scoping, M-ANILIST-15)'
        );
    }

    // ------------------------------------------------------------- 17 ----

    public function testWaitLoopBudgetIsWallTimeNotNominalTicks(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/HttpClient.php');

        $this->assertStringContainsString('hrtime(true)', $src, 'the cooperative wait must deadline against monotonic time');
        $this->assertStringNotContainsString(
            '$waited += self::TICK_SEC',
            $src,
            'counting nominal ticks understates real elapsed time when ticks overrun on a loaded worker'
        );
    }

    // -------------------------------------------------------- helpers ----

    /** @param RecordingHttpClientForW9 $http */
    private function makePluginWithApi(RecordingHttpClientForW9 $http): AniListPlugin
    {
        $api = new AniListApi($http, 'tok', new NullLogger());

        return new AniListPlugin(
            new AniListSettings(accessToken: 'tok', username: 'joe', syncEnabled: true),
            new NullLogger(),
            $api
        );
    }

    /**
     * @param object $object
     * @param array<int, mixed> $args
     *
     * @return mixed
     */
    private function invokePrivate(object $object, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($object, $method);
        $ref->setAccessible(true);

        return $ref->invoke($object, ...$args);
    }
}
