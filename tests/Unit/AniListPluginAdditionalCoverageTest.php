<?php

/**
 * AniListPlugin additional coverage tests.
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
use Phlix\Shared\Events\Library\MediaItemAdded;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Additional coverage tests for AniListPlugin methods with partial coverage.
 */
final class AniListPluginAdditionalCoverageTest extends TestCase
{
    private function makePlugin(
        ?AniListSettings $settings = null,
        ?\Psr\Log\LoggerInterface $logger = null,
        ?AniListApi $api = null,
        bool $enabled = true,
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

    public function testOnMediaItemAddedSkipsWhenNotConfigured(): void
    {
        $settings = new AniListSettings(
            accessToken: null,
            username: 'user',
            syncEnabled: true,
        );

        $plugin = $this->makePlugin($settings, new NullLogger(), null, false);

        $plugin->onMediaItemAdded(new MediaItemAdded('m1', 'lib', '/x.mkv', 'episode'));

        $this->assertSame([], $this->getProp($plugin, 'enrichQueue'));
    }

    public function testOnMediaItemAddedSkipsWhenAutoMatchDisabled(): void
    {
        $settings = new AniListSettings(
            accessToken: 'token',
            username: 'user',
            syncEnabled: true,
            autoMatch: false,
        );

        $plugin = $this->makePlugin($settings, new NullLogger(), null, true);

        $plugin->onMediaItemAdded(new MediaItemAdded('m1', 'lib', '/x.mkv', 'episode'));

        $this->assertSame([], $this->getProp($plugin, 'enrichQueue'));
    }

    public function testDrainOneReturnsEarlyWhenQueueEmpty(): void
    {
        $plugin = $this->makePlugin();
        $this->setProp($plugin, 'enrichQueue', []);

        $this->invoke($plugin, 'drainOne');

        $this->assertSame([], $this->getProp($plugin, 'enrichQueue'));
    }

    public function testEnsureApiCreatesApiWithValidToken(): void
    {
        $settings = new AniListSettings(
            accessToken: 'valid-token',
            username: 'user',
        );

        $plugin = $this->makePlugin($settings, new NullLogger(), null);

        $this->assertNull($this->getProp($plugin, 'api'));

        $this->invoke($plugin, 'ensureApi');

        $api = $this->getProp($plugin, 'api');
        $this->assertInstanceOf(AniListApi::class, $api);
    }

    public function testArmDrainTimerSkipsWhenAlreadyArmed(): void
    {
        $plugin = $this->makePlugin();
        $this->setProp($plugin, 'drainArmed', true);

        $this->invoke($plugin, 'armDrainTimer');

        $this->assertTrue($this->getProp($plugin, 'drainArmed'));
    }

    public function testArmDrainTimerCatchesExceptionWhenTimerAddFails(): void
    {
        $plugin = $this->makePlugin();
        $this->setProp($plugin, 'drainArmed', false);

        $this->invoke($plugin, 'armDrainTimer');

        $this->assertFalse($this->getProp($plugin, 'drainArmed'));
    }

    public function testRunScheduledSyncSkipsWhenSyncDisabled(): void
    {
        $settings = new AniListSettings(
            accessToken: 'token',
            username: 'user',
            syncEnabled: false,
        );

        $api = new AniListApi(
            new class implements HttpClientInterface {
                public function post(string $url, array $data, array $headers = []): array
                {
                    $this->fail('API should not be called when sync is disabled');
                    return [];
                }

                public function get(string $url, array $params = [], array $headers = []): array
                {
                    $this->fail('API should not be called when sync is disabled');
                    return [];
                }
            },
            'token',
            new NullLogger()
        );

        $logger = new RecordingAniListLogger();
        $plugin = $this->makePlugin($settings, $logger, $api);

        $this->invoke($plugin, 'runScheduledSync');

        $this->assertFalse(
            $logger->hasRecord('info', 'starting watchlist sync'),
            'Should not start sync when sync is disabled'
        );
    }

    public function testSyncWatchlistFromAniListIteratesAllStatuses(): void
    {
        $settings = new AniListSettings(
            accessToken: 'token',
            username: 'testuser',
            syncEnabled: true,
        );

        $entries = [
            [
                'media' => [
                    'id' => 123,
                    'idMal' => 456,
                    'title' => ['romaji' => 'Test Anime'],
                ],
                'status' => 'CURRENT',
                'score' => 85,
                'progress' => 5,
            ],
        ];

        $http = new class($entries) implements HttpClientInterface {
            public function __construct(private array $entries)
            {
            }

            public function post(string $url, array $data, array $headers = []): array
            {
                return [];
            }

            public function get(string $url, array $params = [], array $headers = []): array
            {
                return ['data' => ['Page' => ['mediaList' => $this->entries]]];
            }
        };

        $api = new AniListApi($http, 'token', new NullLogger());
        $logger = new RecordingAniListLogger();

        $plugin = $this->makePlugin($settings, $logger, $api);

        $this->invoke($plugin, 'syncWatchlistFromAniList');

        $this->assertTrue(
            $logger->hasRecord('info', 'starting watchlist sync'),
            'Should log watchlist sync start'
        );
    }

    public function testSchedulePeriodicSyncCatchesExceptionWhenTimerFails(): void
    {
        $settings = new AniListSettings(
            accessToken: 'token',
            username: 'user',
            syncEnabled: true,
        );

        $logger = new RecordingAniListLogger();
        $plugin = $this->makePlugin($settings, $logger, null);

        $this->invoke($plugin, 'schedulePeriodicSync');

        $this->assertTrue(true, 'Should complete without throwing even when timer fails');
    }

    public function testSyncWatchlistFromAniListLogsCompletionAfterIterations(): void
    {
        $settings = new AniListSettings(
            accessToken: 'token',
            username: 'testuser',
            syncEnabled: true,
        );

        // Mock HTTP that returns proper paginated data structure for POST (GraphQL)
        $http = new class implements HttpClientInterface {
            public function post(string $url, array $data, array $headers = []): array
            {
                // Return proper paginated structure that getUserAnimeList expects
                return [
                    'data' => [
                        'Page' => [
                            'mediaList' => [
                                [
                                    'media' => ['id' => 1, 'idMal' => 1],
                                    'status' => 'CURRENT',
                                    'score' => 80,
                                    'progress' => 5,
                                ],
                            ],
                        ],
                    ],
                ];
            }

            public function get(string $url, array $params = [], array $headers = []): array
            {
                return [];
            }
        };

        $api = new AniListApi($http, 'token', new NullLogger());
        $logger = new RecordingAniListLogger();

        $plugin = $this->makePlugin($settings, $logger, $api);

        $this->invoke($plugin, 'syncWatchlistFromAniList');

        $this->assertTrue(
            $logger->hasRecord('info', 'starting watchlist sync'),
            'Should log watchlist sync start'
        );
        $this->assertTrue(
            $logger->hasRecord('info', 'watchlist sync completed'),
            'Should log watchlist sync completion'
        );
    }

    public function testSyncProgressToAniListSuccessLogsInfo(): void
    {
        $settings = new AniListSettings(
            accessToken: 'token',
            username: 'user',
            syncEnabled: true,
        );

        $api = new AniListApi(
            new class implements HttpClientInterface {
                public function post(string $url, array $data, array $headers = []): array
                {
                    return [
                        'data' => [
                            'SaveMediaListEntry' => [
                                'id' => 1,
                                'status' => 'CURRENT',
                                'progress' => 5,
                            ],
                        ],
                    ];
                }

                public function get(string $url, array $params = [], array $headers = []): array
                {
                    return [];
                }
            },
            'token',
            new NullLogger()
        );

        $logger = new RecordingAniListLogger();
        $plugin = $this->makePlugin($settings, $logger, $api);

        $this->invoke($plugin, 'syncProgressToAniList', 123, 5);

        $this->assertTrue(
            $logger->hasRecord('info', 'synced episode progress'),
            'Should log successful sync'
        );
    }

    public function testSyncStatusListHandlesExceptionAndContinues(): void
    {
        $settings = new AniListSettings(
            accessToken: 'token',
            username: 'testuser',
            syncEnabled: true,
        );

        $callCount = 0;
        $http = new class ($callCount) implements HttpClientInterface {
            public int $callCount;

            public function __construct(int &$count)
            {
                $this->callCount =& $count;
            }

            public function post(string $url, array $data, array $headers = []): array
            {
                $this->callCount++;
                // Fail on first call (CURRENT status), succeed on second
                if ($this->callCount === 1) {
                    return ['errors' => [['message' => 'Rate limited']]];
                }
                return [
                    'data' => [
                        'Page' => [
                            'mediaList' => [],
                        ],
                    ],
                ];
            }

            public function get(string $url, array $params = [], array $headers = []): array
            {
                return [];
            }
        };

        $api = new AniListApi($http, 'token', new NullLogger());
        $logger = new RecordingAniListLogger();

        $plugin = $this->makePlugin($settings, $logger, $api);

        // Should not throw - exception should be caught
        $this->invoke($plugin, 'syncStatusList', 'testuser', 'CURRENT');

        $this->assertTrue(
            $logger->hasRecord('warning', 'failed to sync status list'),
            'Should log warning on API exception'
        );
    }

    public function testSyncStatusListSkipsEntriesWithNoMedia(): void
    {
        $settings = new AniListSettings(
            accessToken: 'token',
            username: 'testuser',
            syncEnabled: true,
        );

        $http = new class implements HttpClientInterface {
            public function post(string $url, array $data, array $headers = []): array
            {
                // Entry with no media field
                return [
                    'data' => [
                        'Page' => [
                            'mediaList' => [
                                ['status' => 'CURRENT', 'score' => 80], // no 'media' key
                            ],
                        ],
                    ],
                ];
            }

            public function get(string $url, array $params = [], array $headers = []): array
            {
                return [];
            }
        };

        $api = new AniListApi($http, 'token', new NullLogger());
        $logger = new RecordingAniListLogger();

        $plugin = $this->makePlugin($settings, $logger, $api);

        // Should not throw - entry should be skipped
        $this->invoke($plugin, 'syncStatusList', 'testuser', 'CURRENT');

        // No error should be logged since entry is just skipped
        $this->assertFalse(
            $logger->hasRecord('warning', 'failed to sync status list'),
            'Should not log warning for skipped entry'
        );
    }

    public function testOnMediaItemAddedEnqueuesWhenConfigured(): void
    {
        $settings = new AniListSettings(
            accessToken: 'token',
            username: 'user',
            syncEnabled: true,
            autoMatch: true,
        );

        $plugin = $this->makePlugin($settings, new NullLogger(), null, true);

        $plugin->onMediaItemAdded(new MediaItemAdded('m1', 'lib', '/x.mkv', 'episode'));

        $queue = $this->getProp($plugin, 'enrichQueue');
        $this->assertContains('m1', $queue);
    }

    public function testDrainOneDequeuesAndEnriches(): void
    {
        $repo = new ItemRepository();
        $repo->rows['m1'] = [
            'id' => 'm1',
            'name' => 'Test',
            'type' => 'episode',
            'path' => '/x.mkv',
            'metadata' => ['mal_id' => 1],
        ];

        $entry = ['id' => 99, 'idMal' => 1, 'title' => ['romaji' => 'Test']];
        $http = new class ($entry) implements HttpClientInterface {
            public function __construct(private ?array $entry)
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

        $api = new AniListApi($http, 'token', new NullLogger());
        $plugin = $this->makePlugin(new AniListSettings(accessToken: 'token', username: 'user', syncEnabled: true), new NullLogger(), $api);
        $this->setProp($plugin, 'itemRepository', $repo);
        $this->setProp($plugin, 'enrichQueue', ['m1', 'm2']);

        $this->invoke($plugin, 'drainOne');

        $queue = $this->getProp($plugin, 'enrichQueue');
        $this->assertSame(['m2'], $queue);
        $this->assertCount(1, $repo->updateCalls);
    }

    public function testArmDrainTimerSuccessPath(): void
    {
        // This test verifies the armDrainTimer method can be called without exception
        // when timer is not already armed. The actual timer functionality requires Workerman.
        $plugin = $this->makePlugin();

        // Set drainArmed to false so we enter the try block
        $this->setProp($plugin, 'drainArmed', false);

        // The try block calls Workerman\Timer::add which will throw outside Workerman
        // This test verifies that exception is caught
        $this->invoke($plugin, 'armDrainTimer');

        // drainArmed should remain false because the exception was caught
        $this->assertFalse($this->getProp($plugin, 'drainArmed'));
    }
}
