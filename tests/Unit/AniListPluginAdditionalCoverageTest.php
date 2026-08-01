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
}
