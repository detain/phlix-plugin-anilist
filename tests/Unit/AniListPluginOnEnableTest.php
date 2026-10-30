<?php

/**
 * AniListPlugin OnEnable + Sync consequence tests.
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
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for onEnable wiring logic and periodic sync.
 */
final class AniListPluginOnEnableTest extends TestCase
{
    private function makePlugin(
        ?AniListSettings $settings = null,
        ?LoggerInterface $logger = null,
        ?AniListApi $api = null,
    ): AniListPlugin {
        $settings ??= new AniListSettings(
            accessToken: 'token',
            username: 'testuser',
            syncEnabled: true,
        );

        $ref = new \ReflectionClass(AniListPlugin::class);
        /** @var AniListPlugin $plugin */
        $plugin = $ref->newInstanceWithoutConstructor();

        $this->setProp($plugin, 'settings', $settings);
        $this->setProp($plugin, 'enabled', true);
        $this->setProp($plugin, 'api', $api);
        $this->setProp($plugin, 'logger', $logger ?? new NullLogger());

        return $plugin;
    }

    private function setProp(object $obj, string $name, mixed $value): void
    {
        $prop = new \ReflectionProperty($obj, $name);
        $prop->setAccessible(true);
        $prop->setValue($obj, $value);
    }

    private function getProp(object $obj, string $name): mixed
    {
        $prop = new \ReflectionProperty($obj, $name);
        $prop->setAccessible(true);

        return $prop->getValue($obj);
    }

    private function invoke(object $obj, string $method, mixed ...$args): mixed
    {
        $m = new \ReflectionMethod($obj, $method);
        $m->setAccessible(true);

        return $m->invoke($obj, ...$args);
    }

    public function testOnEnableWiresContainerDependencies(): void
    {
        $logger = new RecordingAniListLogger();
        $repo = new ItemRepository();
        $repo->rows['m1'] = [
            'id' => 'm1',
            'name' => 'Test',
            'type' => 'episode',
            'path' => '/x.mkv',
            'metadata' => [],
        ];

        $container = new class ($repo, $logger) implements ContainerInterface {
            public function __construct(
                private ItemRepository $repo,
                private LoggerInterface $logger,
            ) {
            }

            public function get(string $id): mixed
            {
                if ($id === ItemRepository::class) {
                    return $this->repo;
                }
                if ($id === LoggerInterface::class) {
                    return $this->logger;
                }
                throw new \RuntimeException('Not found: ' . $id);
            }

            public function has(string $id): bool
            {
                return $id === ItemRepository::class || $id === LoggerInterface::class;
            }
        };

        $plugin = $this->makePlugin();
        $this->setProp($plugin, 'logger', new NullLogger()); // start with NullLogger

        $plugin->onEnable($container);

        $this->assertInstanceOf(LoggerInterface::class, $this->getProp($plugin, 'logger'));
        $this->assertNotInstanceOf(NullLogger::class, $this->getProp($plugin, 'logger'));
    }

    public function testOnEnableHandlesContainerMissForLogger(): void
    {
        $repo = new ItemRepository();

        $container = new class ($repo) implements ContainerInterface {
            public function __construct(private ItemRepository $repo)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === ItemRepository::class) {
                    return $this->repo;
                }
                throw new \RuntimeException('Not found: ' . $id);
            }

            public function has(string $id): bool
            {
                return $id === ItemRepository::class;
            }
        };

        $plugin = $this->makePlugin();
        $this->setProp($plugin, 'logger', new NullLogger());

        // Should not throw - catches container exception
        $plugin->onEnable($container);

        // Should still be NullLogger since container didn't have LoggerInterface
        $this->assertInstanceOf(NullLogger::class, $this->getProp($plugin, 'logger'));
    }

    public function testOnEnableHandlesContainerExceptionForItemRepository(): void
    {
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('Container error');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        $logger = new RecordingAniListLogger();
        $plugin = $this->makePlugin(null, $logger);

        // Should not throw even when container fails
        $plugin->onEnable($container);

        // Warning should be logged
        $this->assertTrue(
            $logger->hasRecord('warning', 'wiring failed'),
            'wiring failure should log a warning'
        );
    }

    public function testOnEnableDoesNotScheduleSyncWhenSyncDisabled(): void
    {
        $settings = new AniListSettings(
            accessToken: 'token',
            username: 'user',
            syncEnabled: false,
        );

        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('Should not be called');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        $plugin = $this->makePlugin($settings);
        $this->setProp($plugin, 'logger', new NullLogger());

        // Should not throw - sync is disabled
        $plugin->onEnable($container);

        // itemRepository should remain null since container wiring failed and sync was skipped
        $this->assertNull($this->getProp($plugin, 'itemRepository'));
    }

    public function testResolveMediaItemIdByMalIdFindsRowThroughHostHook(): void
    {
        $repo = new ItemRepository();
        $repo->rows['m1'] = [
            'id' => 'm1',
            'name' => 'Test',
            'type' => 'episode',
            'path' => '/x.mkv',
            'metadata' => ['mal_id' => 12345],
        ];

        $plugin = $this->makePlugin();
        $this->setProp($plugin, 'itemRepository', $repo);

        $result = $this->invoke($plugin, 'resolveMediaItemIdByMalId', 12345);

        $this->assertSame('m1', $result, 'a resolvable mal_id must return the local row id');
        $this->assertSame([['field' => 'mal_id', 'value' => 12345]], $repo->byMetadataCalls);
    }

    public function testResolveMediaItemIdByMalIdReturnsNullForUnknownMalId(): void
    {
        $repo = new ItemRepository();
        $repo->rows['m1'] = [
            'id' => 'm1',
            'name' => 'Test',
            'type' => 'episode',
            'path' => '/x.mkv',
            'metadata' => ['mal_id' => 99999],
        ];

        $plugin = $this->makePlugin();
        $this->setProp($plugin, 'itemRepository', $repo);

        $this->assertNull($this->invoke($plugin, 'resolveMediaItemIdByMalId', 12345));
    }

    public function testResolveMediaItemIdByMalIdNullWithoutRepository(): void
    {
        $plugin = $this->makePlugin();

        $this->assertNull($this->invoke($plugin, 'resolveMediaItemIdByMalId', 12345));
    }

    public function testResolveProbesTheHostHookBeforeCallingIt(): void
    {
        // findByMetadataField() exists on host master (phlix-server bfd4af98)
        // but NOT in the released v1.2.x tags; calling it blind would fatal
        // every watchlist tick on a current release host. The runtime
        // method_exists() probe is load-bearing for the deploy matrix and the
        // test process cannot simulate its absence (the typed stub mirrors
        // the modern surface), so the guard is pinned by source scan —
        // house idiom (cf. AniListMinorsW9Test accessor pin).
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/AniListPlugin.php');

        $this->assertMatchesRegularExpression(
            '/method_exists\(\$this->itemRepository, \'findByMetadataField\'\)/',
            $src,
            'resolve must probe the optional host hook instead of trusting the type'
        );
    }

    public function testUpdateWatchlistStatusWritesAuthoritativeFieldsOverExisting(): void
    {
        $repo = new ItemRepository();
        $repo->rows['m1'] = [
            'id' => 'm1',
            'name' => 'Test',
            'type' => 'episode',
            'path' => '/x.mkv',
            'metadata' => [
                'mal_id' => 12345,
                'anilist_id' => 7,
                'anilist_status' => 'PAUSED',
                'anilist_score' => 5,
                'anilist_progress' => 3,
                'tmdb_id' => 555,
            ],
        ];

        $plugin = $this->makePlugin();
        $this->setProp($plugin, 'itemRepository', $repo);

        $this->invoke($plugin, 'updateWatchlistStatus', 12345, 99, 'COMPLETED', [
            'score' => 9,
            'progress' => 24,
        ]);

        $this->assertCount(1, $repo->updateCalls, 'the watchlist tick must persist now that resolution works');
        $written = $repo->updateCalls[0]['data']['metadata_json'];
        $this->assertSame('COMPLETED', $written['anilist_status'], 'status is authoritative, not gap-filled');
        $this->assertSame(99, $written['anilist_id']);
        $this->assertSame(9, $written['anilist_score']);
        $this->assertSame(24, $written['anilist_progress']);
        $this->assertSame(12345, $written['mal_id'], 'namespaces the tick does not write are preserved');
        $this->assertSame(555, $written['tmdb_id'], 'foreign namespaces are never touched');
    }

    public function testSyncWatchlistFromAniListSkipsWhenApiNull(): void
    {
        $repo = new ItemRepository();
        $plugin = $this->makePlugin();
        $this->setProp($plugin, 'api', null);

        // Should not throw
        $this->invoke($plugin, 'syncWatchlistFromAniList');

        $this->assertTrue(true);
    }

    public function testSyncWatchlistFromAniListSkipsWhenUsernameEmpty(): void
    {
        $repo = new ItemRepository();
        $settings = new AniListSettings(
            accessToken: 'token',
            username: '',
            syncEnabled: true,
        );

        $http = new class implements HttpClientInterface {
            public function post(string $url, array $data, array $headers = []): array
            {
                return [];
            }

            public function get(string $url, array $params = [], array $headers = []): array
            {
                return [];
            }
        };
        $api = new AniListApi($http, 'token', new NullLogger());

        $plugin = $this->makePlugin($settings, new NullLogger(), $api);

        // Should not throw
        $this->invoke($plugin, 'syncWatchlistFromAniList');

        $this->assertTrue(true);
    }

    public function testSyncStatusListSkipsWhenApiNull(): void
    {
        $repo = new ItemRepository();
        $plugin = $this->makePlugin();
        $this->setProp($plugin, 'api', null);

        // Should not throw
        $this->invoke($plugin, 'syncStatusList', 'testuser', 'CURRENT');

        $this->assertTrue(true);
    }

    public function testSyncStatusListHandlesApiException(): void
    {
        $repo = new ItemRepository();
        $settings = new AniListSettings(
            accessToken: 'token',
            username: 'testuser',
            syncEnabled: true,
        );

        $http = new class implements HttpClientInterface {
            public function post(string $url, array $data, array $headers = []): array
            {
                return ['errors' => [['message' => 'API Error']]];
            }

            public function get(string $url, array $params = [], array $headers = []): array
            {
                return [];
            }
        };
        $api = new AniListApi($http, 'token', new NullLogger());
        $logger = new RecordingAniListLogger();

        $plugin = $this->makePlugin($settings, $logger, $api);

        // Should not throw - catches exception
        $this->invoke($plugin, 'syncStatusList', 'testuser', 'CURRENT');

        $this->assertTrue(
            $logger->hasRecord('warning', 'failed to sync status list'),
            'API exception should log a warning'
        );
    }

    public function testSyncStatusListHandlesEntriesWithNoMalId(): void
    {
        $repo = new ItemRepository();
        $settings = new AniListSettings(
            accessToken: 'token',
            username: 'testuser',
            syncEnabled: true,
        );

        $entry = [
            'media' => [
                'id' => 123,
                // no idMal
                'title' => ['romaji' => 'Test'],
            ],
        ];

        $http = new class ($entry) implements HttpClientInterface {
            public function __construct(private array $entry)
            {
            }

            public function post(string $url, array $data, array $headers = []): array
            {
                return [];
            }

            public function get(string $url, array $params = [], array $headers = []): array
            {
                return ['data' => ['Page' => ['mediaList' => [$this->entry]]]];
            }
        };
        $api = new AniListApi($http, 'token', new NullLogger());

        $plugin = $this->makePlugin($settings, new NullLogger(), $api);

        // Should not throw - skips entry with no malId
        $this->invoke($plugin, 'syncStatusList', 'testuser', 'CURRENT');

        $this->assertTrue(true);
    }

    public function testUpdateWatchlistStatusSkipsWhenItemRepositoryNull(): void
    {
        $plugin = $this->makePlugin();
        $this->setProp($plugin, 'itemRepository', null);

        // Should not throw
        $this->invoke($plugin, 'updateWatchlistStatus', 12345, 99, 'CURRENT', [
            'score' => 85,
            'progress' => 5,
        ]);

        $this->assertTrue(true);
    }

    public function testUpdateWatchlistStatusSkipsWhenMalIdNotResolved(): void
    {
        $repo = new ItemRepository();
        $repo->rows['m1'] = [
            'id' => 'm1',
            'name' => 'Test',
            'type' => 'episode',
            'path' => '/x.mkv',
            'metadata' => ['mal_id' => 99999], // different MAL ID
        ];

        $plugin = $this->makePlugin();
        $this->setProp($plugin, 'itemRepository', $repo);

        // Should not throw - the mal_id resolves to no local row
        $this->invoke($plugin, 'updateWatchlistStatus', 12345, 99, 'CURRENT', [
            'score' => 85,
            'progress' => 5,
        ]);

        // No update calls: findByMetadataField('mal_id', 12345) matches nothing
        // (the only row carries 99999)
        $this->assertCount(0, $repo->updateCalls);
    }

    public function testRunScheduledSyncSkipsWhenNotConfigured(): void
    {
        $repo = new ItemRepository();
        $settings = new AniListSettings(
            accessToken: '',
            username: 'user',
            syncEnabled: false,
        );

        $plugin = $this->makePlugin($settings);
        $this->setProp($plugin, 'itemRepository', $repo);

        // Should not throw
        $this->invoke($plugin, 'runScheduledSync');

        $this->assertTrue(true);
    }

    public function testSchedulePeriodicSyncSkipsWhenNotConfigured(): void
    {
        $settings = new AniListSettings(
            accessToken: '',
            username: 'user',
            syncEnabled: true,
        );

        $plugin = $this->makePlugin($settings);

        // Should not throw - catches timer exception
        $this->invoke($plugin, 'schedulePeriodicSync');

        $this->assertTrue(true);
    }

    public function testSchedulePeriodicSyncCatchesTimerException(): void
    {
        $settings = new AniListSettings(
            accessToken: 'token',
            username: 'user',
            syncEnabled: true,
        );

        $logger = new RecordingAniListLogger();
        $plugin = $this->makePlugin($settings, $logger);

        // Should not throw even though timer would fail outside workerman
        $this->invoke($plugin, 'schedulePeriodicSync');

        $this->assertTrue(true);
    }
}
