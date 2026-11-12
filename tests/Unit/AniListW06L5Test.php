<?php

/**
 * Consequence tests for the w06L5 fix batch (register anchors
 * plugin-anilist__srphp.md #1-#5 and plugin-anilist__secaudit.md #2).
 *
 * Laws pinned here:
 * 1. the master enable toggle gates the PERIODIC sync paths, not just the
 *    event paths — a disabled-but-connected worker never dials AniList and
 *    never arms a sync timer;
 * 2. the lazily built API client is dropped by configure() and onDisable() —
 *    a rotated access_token must reach the wire without a restart, and a
 *    disabled plugin must not hold the bearer secret or its TLS pools;
 * 3. the repeating periodic-sync timer keeps its id, refuses double-arming,
 *    and is removed by onDisable() — enable/disable cycles must not stack
 *    live watchlist-walking timers;
 * 4. AniList MediaList scores are Float (half-point scores like 8.5) and
 *    must survive into metadata, not be dropped by an is_int() check;
 * 5. remote-controlled content entering logs is scrubbed and bounded (the
 *    enrichment title, the SaveMediaListEntry result, and vendor transport
 *    error text alike).
 *
 * Test order: every case that needs no timer spy comes first — at the parent
 * commit the spy cases below die on the pre-fix `final` class modifier, and
 * the register of per-test failures above that fatal is the red-parent
 * evidence for each law independently. Sole exception: finding #1's
 * arm-path pin (`test_periodic_timer_never_arms_while_disabled`) was rebuilt
 * through the spy in a later batch (its plain-plugin form could not fail),
 * and now sits as a spy consumer inside the no-spy run.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Metadata\AniList;

use Phlix\Media\Library\ItemRepository;
use Phlix\Plugins\Metadata\AniList\AniListApi;
use Phlix\Plugins\Metadata\AniList\AniListPlugin;
use Phlix\Plugins\Metadata\AniList\AniListSettings;
use Phlix\Plugins\Metadata\AniList\HttpClientInterface;
use Phlix\Plugins\Metadata\AniList\HttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class AniListW06L5Test extends TestCase
{
    /**
     * Build a plugin instance without touching the constructor's wiring,
     * mirroring the sibling suites' harness. Forced `enabled` is exactly the
     * blind spot register finding #1 named (every legacy fixture passed
     * true), so this variant defaults to false and each test opts in.
     */
    private function makePlugin(
        ?AniListSettings $settings = null,
        ?LoggerInterface $logger = null,
        ?AniListApi $api = null,
        bool $enabled = false,
    ): AniListPlugin {
        $settings ??= new AniListSettings(
            accessToken: 'test-token',
            username: 'testuser',
            syncEnabled: true,
        );

        $ref = new \ReflectionClass(AniListPlugin::class);
        /** @var AniListPlugin $plugin */
        $plugin = $ref->newInstanceWithoutConstructor();

        $this->setProp($plugin, 'settings', $settings);
        $this->setProp($plugin, 'enabled', $enabled);
        $this->setProp($plugin, 'api', $api);
        $this->setProp($plugin, 'logger', $logger ?? new NullLogger());
        $this->setProp($plugin, 'itemRepository', new ItemRepository());

        return $plugin;
    }

    /**
     * AniList API double that counts list fetches: the pre-seeded client is
     * finding #1's repro — with enabled=false the sync tick must never reach
     * it.
     */
    private function countingApi(): AniListApi
    {
        return new class extends AniListApi {
            public int $listCalls = 0;

            public function __construct()
            {
                $http = new class implements HttpClientInterface {
                    public function post(string $url, array $data, array $headers = []): array
                    {
                        throw new \LogicException('unused double');
                    }

                    public function get(string $url, array $params = [], array $headers = []): array
                    {
                        throw new \LogicException('unused double');
                    }
                };
                parent::__construct($http, 'test-token', new NullLogger());
            }

            /**
             * @param string|null $status
             *
             * @return list<array<string, mixed>>
             */
            public function getUserAnimeList(string $username, ?string $status = null, int $page = 1, int $perPage = 50): array
            {
                $this->listCalls++;

                return [];
            }
        };
    }

    /**
     * Resolve a property through the declaring class of the hierarchy.
     *
     * A ReflectionProperty anchored on the object itself cannot see a
     * PRIVATE property declared by a parent — which is what the anonymous
     * timer spy (an AniListPlugin subclass) runs into. Walking up to the
     * declaring class is the only way to reach those, and it reads the
     * same slot the parent's own code does.
     */
    private function prop(object $obj, string $name): \ReflectionProperty
    {
        for ($class = new \ReflectionClass($obj); $class !== false; $class = $class->getParentClass()) {
            if ($class->hasProperty($name)) {
                return $class->getProperty($name);
            }
        }

        throw new \RuntimeException("Property {$name} not found on " . $obj::class);
    }

    private function setProp(object $obj, string $name, mixed $value): void
    {
        $this->prop($obj, $name)->setValue($obj, $value);
    }

    private function getProp(object $obj, string $name): mixed
    {
        return $this->prop($obj, $name)->getValue($obj);
    }

    private function invoke(object $obj, string $method, mixed ...$args): mixed
    {
        $m = new \ReflectionMethod($obj, $method);
        $m->setAccessible(true);

        return $m->invoke($obj, ...$args);
    }

    /**
     * @return array{level: string, message: string, context: array<mixed>}
     */
    private function firstRecord(RecordingAniListLogger $logger, string $level, string $messageNeedle): array
    {
        foreach ($logger->records as $record) {
            if ($record['level'] === $level && str_contains($record['message'], $messageNeedle)) {
                return $record;
            }
        }

        $this->fail("expected a {$level} record containing '{$messageNeedle}'");
    }

    // ------------------------------------------------------------------
    // Finding #1 (Major): the master enable toggle must gate the timer paths.
    // ------------------------------------------------------------------

    public function test_scheduled_sync_never_dials_anilist_while_disabled(): void
    {
        $api = $this->countingApi();
        $plugin = $this->makePlugin(
            new AniListSettings(accessToken: 'test-token', username: 'testuser', syncEnabled: true),
            new NullLogger(),
            $api,
            enabled: false,
        );

        $this->invoke($plugin, 'runScheduledSync');

        $this->assertSame(
            0,
            $api->listCalls,
            'a disabled plugin with a stored token must not walk the AniList watchlist'
        );
    }

    public function test_scheduled_sync_still_walks_when_enabled(): void
    {
        $api = $this->countingApi();
        $plugin = $this->makePlugin(
            new AniListSettings(accessToken: 'test-token', username: 'testuser', syncEnabled: true),
            new NullLogger(),
            $api,
            enabled: true,
        );

        $this->invoke($plugin, 'runScheduledSync');

        $this->assertGreaterThan(0, $api->listCalls, 'the positive control must still sync');
    }

    public function test_periodic_timer_never_arms_while_disabled(): void
    {
        // Rebuilt through the timer spy (review w06L5, Minor 1). The original
        // drove schedulePeriodicSync on a plain plugin, so the arm fell into
        // the REAL addRepeatingTimer() seam — whose catch-throwable arm
        // returns null in a test process whatever the gate decides — and the
        // assertNull could not fail. Through the spy the refusal is observed
        // where the law lives: an un-gated armer appends an arm.
        $plugin = $this->makeTimerSpyPlugin(enabled: false);

        $this->invoke($plugin, 'schedulePeriodicSync');

        $this->assertSame(
            [],
            $plugin->arms,
            'a disabled-but-connected plugin must not even attempt to arm the periodic timer'
        );
        $this->assertNull($this->getProp($plugin, 'periodicTimerId'));
    }

    // ------------------------------------------------------------------
    // Finding #2 (Major): the cached client is dropped on reconfigure/disable.
    // ------------------------------------------------------------------

    public function test_configure_drops_the_cached_api_client(): void
    {
        $plugin = $this->makePlugin(null, new NullLogger(), $this->countingApi(), enabled: true);

        $plugin->configure([
            'enabled' => true,
            'access_token' => 'rotated-token',
            'username' => 'otheruser',
            'sync_enabled' => true,
        ]);

        $this->assertNull(
            $this->getProp($plugin, 'api'),
            'configure() must invalidate the lazily built client or a rotated token never reaches the wire'
        );
    }

    public function test_configure_then_ensureapi_rebuilds_with_the_rotated_token(): void
    {
        $plugin = $this->makePlugin(null, new NullLogger(), null, enabled: true);

        $this->invoke($plugin, 'ensureApi');
        $first = $this->getProp($plugin, 'api');
        $this->assertNotNull($first, 'ensureApi() must build the client once');

        $plugin->configure([
            'enabled' => true,
            'access_token' => 'rotated-token',
            'username' => 'testuser',
            'sync_enabled' => true,
        ]);

        $this->assertNull($this->getProp($plugin, 'api'));

        $this->invoke($plugin, 'ensureApi');
        $rebuilt = $this->getProp($plugin, 'api');
        $this->assertNotNull($rebuilt, 'the next call must lazily rebuild on the new token');

        $token = new \ReflectionProperty(AniListApi::class, 'accessToken');
        $token->setAccessible(true);
        $this->assertSame('rotated-token', $token->getValue($rebuilt), 'the wire must sign with the rotated token');
    }

    public function test_on_disable_releases_the_api_client(): void
    {
        $plugin = $this->makePlugin(null, new NullLogger(), $this->countingApi(), enabled: true);

        $plugin->onDisable();

        $this->assertNull($this->getProp($plugin, 'api'), 'a disabled plugin must not retain the bearer secret');
        $this->assertNull($this->getProp($plugin, 'itemRepository'));
    }

    // ------------------------------------------------------------------
    // Finding #4 (Minor): Float scores are user scores, not garbage.
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{mixed, int|float|null}>
     */
    public static function scoreProvider(): array
    {
        return [
            'half-point float' => [8.5, 8.5],
            'whole-number float' => [9.0, 9.0],
            'int score' => [9, 9],
            'string score rejected' => ['8.5', null],
            'missing score' => [null, null],
        ];
    }

    /**
     * @param mixed        $raw      remote score value
     * @param int|float|null $expected stored projection (null = contributes nothing)
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('scoreProvider')]
    public function test_watchlist_score_boundary_accepts_int_and_float(mixed $raw, int|float|null $expected): void
    {
        $repo = new ItemRepository();
        $repo->rows['m1'] = [
            'id' => 'm1',
            'name' => 'Test',
            'type' => 'episode',
            'path' => '/x.mkv',
            'metadata' => ['mal_id' => 12345],
        ];

        $plugin = $this->makePlugin(null, new NullLogger(), null, enabled: true);
        $this->setProp($plugin, 'itemRepository', $repo);

        $this->invoke($plugin, 'updateWatchlistStatus', 12345, 99, 'COMPLETED', [
            'score' => $raw,
            'progress' => 12,
        ]);

        $this->assertCount(1, $repo->updateCalls);
        $written = $repo->updateCalls[0]['data']['metadata_json'];

        if ($expected === null) {
            // An unusable score contributes NOTHING — the merge law at
            // gapFillMerge() never carries an empty value into the blob.
            $this->assertArrayNotHasKey('anilist_score', $written);
        } else {
            $this->assertSame($expected, $written['anilist_score']);
        }

        $this->assertSame(12, $written['anilist_progress']);
    }

    public function test_watchlist_stores_score_format_alongside_score(): void
    {
        $repo = new ItemRepository();
        $repo->rows['m1'] = [
            'id' => 'm1',
            'name' => 'Test',
            'type' => 'episode',
            'path' => '/x.mkv',
            'metadata' => ['mal_id' => 12345],
        ];
        $plugin = $this->makePlugin(null, new NullLogger(), null, enabled: true);
        $this->setProp($plugin, 'itemRepository', $repo);

        $this->invoke($plugin, 'updateWatchlistStatus', 12345, 99, 'COMPLETED', [
            'score' => 8.5,
            'progress' => 12,
            'user' => ['mediaListOptions' => ['scoreFormat' => 'POINT_10_DECIMAL']],
        ]);

        $written = $repo->updateCalls[0]['data']['metadata_json'];
        $this->assertSame(8.5, $written['anilist_score']);
        $this->assertSame('POINT_10_DECIMAL', $written['anilist_score_format']);
    }

    // ------------------------------------------------------------------
    // Finding #5 (Minor) / secaudit #2: logs carry scrubbed, bounded,
    // remote-derived values only.
    // ------------------------------------------------------------------

    public function test_enrichment_log_carries_scrubbed_bounded_title(): void
    {
        $dirtyTitle = "Hellsing \u{202E}" . str_repeat('o', 400);

        $entry = ['id' => 99, 'idMal' => 1, 'title' => ['romaji' => $dirtyTitle]];
        $http = new class ($entry) implements HttpClientInterface {
            public function __construct(private array $entry)
            {
            }

            public function post(string $url, array $data, array $headers = []): array
            {
                return ['data' => ['Media' => $this->entry]];
            }

            public function get(string $url, array $params = [], array $headers = []): array
            {
                return [];
            }
        };

        $repo = new ItemRepository();
        $repo->rows['m1'] = [
            'id' => 'm1',
            'name' => 'Test',
            'type' => 'episode',
            'path' => '/x.mkv',
            'metadata' => ['mal_id' => 1],
        ];

        $logger = new RecordingAniListLogger();
        $plugin = $this->makePlugin(
            null,
            $logger,
            new AniListApi($http, 'test-token', new NullLogger()),
            enabled: true,
        );
        $this->setProp($plugin, 'itemRepository', $repo);

        $this->invoke($plugin, 'enrichMediaItem', 'm1');

        $record = $this->firstRecord($logger, 'info', 'AniList: enriched media item');
        $logged = $record['context']['title'];

        $this->assertIsString($logged, 'the logged title must be a bounded printable projection');
        $this->assertStringNotContainsString("\u{202E}", $logged, 'bidi override must not reach the log stream');
        $this->assertLessThanOrEqual(211, mb_strlen($logged), 'title projection = cap + [truncated] marker');
        $this->assertStringContainsString('Hellsing', $logged, 'the projection must stay recognizable');

        // The stored copy keeps the full scrubbed text (not the log cap):
        $saved = $repo->updateCalls[0]['data']['metadata_json'];
        $this->assertIsString($saved['title_romaji']);
        $this->assertStringNotContainsString("\u{202E}", $saved['title_romaji']);
    }

    public function test_progress_sync_log_bounds_the_remote_result(): void
    {
        $api = new class extends AniListApi {
            public function __construct()
            {
                $http = new class implements HttpClientInterface {
                    public function post(string $url, array $data, array $headers = []): array
                    {
                        throw new \LogicException('unused double');
                    }

                    public function get(string $url, array $params = [], array $headers = []): array
                    {
                        throw new \LogicException('unused double');
                    }
                };
                parent::__construct($http, 'test-token', new NullLogger());
            }

            public function getMediaListProgress(int $mediaId): ?int
            {
                return null;
            }

            /**
             * @return array<string, mixed>
             */
            public function saveEpisodeProgress(int $mediaId, int $episode, ?int $score = null, ?string $status = null): array
            {
                return [
                    'id' => 1,
                    'status' => 'CURRENT',
                    'progress' => $episode,
                    'note' => str_repeat('A', 5000),
                ];
            }
        };

        $logger = new RecordingAniListLogger();
        $plugin = $this->makePlugin(null, $logger, $api, enabled: true);

        $this->invoke($plugin, 'syncProgressToAniList', 123, 7);

        $record = $this->firstRecord($logger, 'info', 'AniList: synced episode progress');
        $logged = $record['context']['result'];

        $this->assertIsString($logged, 'the remote result must enter the log as a bounded string projection');
        $this->assertLessThanOrEqual(211, mb_strlen($logged), 'result projection = cap + [truncated] marker');
        $this->assertStringContainsString('[truncated]', $logged);
    }

    public function test_transport_error_text_is_scrubbed_and_bounded_before_throw(): void
    {
        // Workerman\Http\Client never exists in the test process, so the real
        // sendOnce closure body is unreachable here; the treatment at the
        // throw site is pinned by source scan — house idiom (cf.
        // AniListPluginOnEnableTest::testResolveProbesTheHostHookBeforeCallingIt).
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/HttpClient.php');

        $this->assertMatchesRegularExpression(
            '/throw new AniListApiException\(\s*\'AniList HTTP error: \' \. self::safeErrorBodySnippet\(/',
            $src,
            'vendor transport error text is remote-influenced: it must pass the same scrub-and-cap as 4xx/5xx bodies'
        );

        $snippet = new \ReflectionMethod(HttpClient::class, 'safeErrorBodySnippet');
        $snippet->setAccessible(true);

        /** @var string $kept */
        $kept = $snippet->invoke(null, 'Connection refused');
        $this->assertSame('Connection refused', $kept, 'clean transport prose must survive unchanged');

        /** @var string $poisoned */
        $poisoned = $snippet->invoke(null, "line one\n\u{202E}" . str_repeat('b', 2000));
        $this->assertStringNotContainsString("\n", $poisoned);
        $this->assertStringNotContainsString("\u{202E}", $poisoned);
        $this->assertLessThanOrEqual(511, mb_strlen($poisoned), 'cap + [truncated] marker');
    }

    // ------------------------------------------------------------------
    // Finding #3 (Major): the repeating timer is tracked and disarmed.
    // These need the protected timer seams (the spy subclasses AniListPlugin,
    // which the pre-fix `final` modifier forbids — itself part of the
    // parent-side evidence).
    // ------------------------------------------------------------------

    /**
     * Plugin subclass whose Workerman timer operations are replaced with
     * recorders. The parent class's private code path calls the seams through
     * $this, so the override observes arming and removal in a test process
     * where the real Workerman\Timer class does not exist.
     */
    private function makeTimerSpyPlugin(bool $enabled): object
    {
        $plugin = new class extends AniListPlugin {
            /** @var list<int> */
            public array $arms = [];

            /** @var list<int> */
            public array $removals = [];

            protected function addRepeatingTimer(int $intervalSeconds, callable $callback): ?int
            {
                $this->arms[] = $intervalSeconds;

                return 777;
            }

            protected function removeRepeatingTimer(int $timerId): void
            {
                $this->removals[] = $timerId;
            }
        };

        $this->setProp($plugin, 'settings', new AniListSettings(
            accessToken: 'test-token',
            username: 'testuser',
            syncEnabled: true,
            syncIntervalMinutes: 15,
        ));
        $this->setProp($plugin, 'enabled', $enabled);
        $this->setProp($plugin, 'logger', new NullLogger());
        $this->setProp($plugin, 'itemRepository', new ItemRepository());

        return $plugin;
    }

    public function test_periodic_timer_arms_once_with_the_resolved_interval(): void
    {
        $plugin = $this->makeTimerSpyPlugin(enabled: true);

        $this->invoke($plugin, 'schedulePeriodicSync');
        $this->invoke($plugin, 'schedulePeriodicSync');
        $this->invoke($plugin, 'schedulePeriodicSync');

        $this->assertSame(
            [900],
            $plugin->arms,
            'repeat arming calls must be refused, and the interval must come from sync_interval_minutes (15)'
        );
        $this->assertSame(777, $this->getProp($plugin, 'periodicTimerId'));
    }

    public function test_on_disable_removes_the_periodic_timer(): void
    {
        $plugin = $this->makeTimerSpyPlugin(enabled: true);

        $this->invoke($plugin, 'schedulePeriodicSync');
        $plugin->onDisable();

        $this->assertSame([777], $plugin->removals, 'the repeating timer must be disarmed on disable');
        $this->assertNull($this->getProp($plugin, 'periodicTimerId'), 'a cleared id must not double-remove');

        $plugin->onDisable();
        $this->assertSame([777], $plugin->removals, 'a second disable must not remove the same id again');
    }

    public function test_rearm_after_disable_arms_a_single_fresh_timer(): void
    {
        $plugin = $this->makeTimerSpyPlugin(enabled: true);

        $this->invoke($plugin, 'schedulePeriodicSync');
        $plugin->onDisable();
        $this->invoke($plugin, 'schedulePeriodicSync');

        $this->assertSame([900, 900], $plugin->arms, 'one arm per enable cycle, never stacked');
        $this->assertSame([777], $plugin->removals);
    }

    public function test_on_enable_does_not_arm_the_periodic_timer_while_disabled(): void
    {
        $plugin = $this->makeTimerSpyPlugin(enabled: false);

        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === ItemRepository::class) {
                    return new ItemRepository();
                }

                return new NullLogger();
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        $plugin->onEnable($container);

        $this->assertSame([], $plugin->arms);
    }

    public function test_timer_seams_are_protected_extension_points(): void
    {
        // The class loses `final` with intent, mirroring HttpClient in this
        // repo: Workerman\Timer does not exist in a test process, so the
        // repeating-timer arm/disarm operations are the sanctioned seam for
        // observing the lifecycle laws pinned in this file.
        $ref = new \ReflectionClass(AniListPlugin::class);

        $this->assertTrue($ref->hasMethod('addRepeatingTimer'));
        $this->assertTrue($ref->getMethod('addRepeatingTimer')->isProtected());
        $this->assertTrue($ref->hasMethod('removeRepeatingTimer'));
        $this->assertTrue($ref->getMethod('removeRepeatingTimer')->isProtected());
    }
}
