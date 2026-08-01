<?php

/**
 * AniList Plugin Event Handlers and Lifecycle Test.
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
use Phlix\Shared\Events\Playback\PlaybackStopped;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for plugin event handlers and lifecycle methods.
 * These cover onPlaybackStopped, getSettings, getSettingsForSpa, onDisable, and subscribedEvents.
 */
final class AniListPluginLifecycleTest extends TestCase
{
    private function makePlugin(
        ItemRepository $repo,
        ?AniListApi $api = null,
        ?AniListSettings $settings = null,
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
        $this->setProp($plugin, 'itemRepository', $repo);
        $this->setProp($plugin, 'api', $api);
        $this->setProp($plugin, 'logger', new NullLogger());

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

    public function testGetSettingsReturnsSettingsObject(): void
    {
        $repo = new ItemRepository();
        $settings = new AniListSettings(
            accessToken: 'my-token',
            username: 'myuser',
            syncEnabled: false,
            syncIntervalMinutes: 30,
            autoMatch: false,
        );
        $plugin = $this->makePlugin($repo, null, $settings);

        $result = $plugin->getSettings();

        $this->assertSame($settings, $result);
        $this->assertSame('my-token', $result->accessToken);
        $this->assertSame('myuser', $result->username);
        $this->assertFalse($result->syncEnabled);
        $this->assertSame(30, $result->syncIntervalMinutes);
        $this->assertFalse($result->autoMatch);
    }

    public function testGetSettingsForSpaReturnsRedactedSettings(): void
    {
        $repo = new ItemRepository();
        $settings = new AniListSettings(
            accessToken: 'secret-token',
            username: 'testuser',
            syncEnabled: true,
            syncIntervalMinutes: 45,
            autoMatch: false,
        );
        $plugin = $this->makePlugin($repo, null, $settings);

        $result = $plugin->getSettingsForSpa();

        $this->assertArrayNotHasKey('access_token', $result);
        $this->assertArrayNotHasKey('accessToken', $result);
        $this->assertSame('testuser', $result['username']);
        $this->assertTrue($result['sync_enabled']);
        $this->assertSame(45, $result['sync_interval_minutes']);
        $this->assertFalse($result['auto_match']);
        $this->assertTrue($result['has_token']);
    }

    public function testGetSettingsForSpaShowsNoTokenWhenEmpty(): void
    {
        $repo = new ItemRepository();
        $settings = new AniListSettings(
            accessToken: null,
            username: 'testuser',
        );
        $plugin = $this->makePlugin($repo, null, $settings);

        $result = $plugin->getSettingsForSpa();

        $this->assertFalse($result['has_token']);
    }

    public function testOnDisableClearsItemRepository(): void
    {
        $repo = new ItemRepository();
        $plugin = $this->makePlugin($repo);

        $this->assertSame($repo, $this->getProp($plugin, 'itemRepository'));

        $plugin->onDisable();

        $this->assertNull($this->getProp($plugin, 'itemRepository'));
    }

    public function testSubscribedEventsReturnsCorrectMap(): void
    {
        $plugin = new AniListPlugin();

        $events = $plugin->subscribedEvents();

        $this->assertIsArray($events);
        $this->assertCount(2, $events);
        $this->assertArrayHasKey(\Phlix\Shared\Events\Library\MediaItemAdded::class, $events);
        $this->assertArrayHasKey(\Phlix\Shared\Events\Playback\PlaybackStopped::class, $events);
        $this->assertSame('onMediaItemAdded', $events[\Phlix\Shared\Events\Library\MediaItemAdded::class]);
        $this->assertSame('onPlaybackStopped', $events[\Phlix\Shared\Events\Playback\PlaybackStopped::class]);
    }

    public function testOnPlaybackStoppedSkipsWhenNotConfigured(): void
    {
        $repo = new ItemRepository();
        $plugin = $this->makePlugin($repo, null, null, false);

        // This should not throw and should not call the API
        $event = new PlaybackStopped('session-1', 'user-1', 'm1', 'device-1', 0, true);
        $plugin->onPlaybackStopped($event);

        // No exception means the early return worked
        $this->assertTrue(true);
    }

    public function testOnPlaybackStoppedSkipsWhenSyncDisabled(): void
    {
        $repo = new ItemRepository();
        $settings = new AniListSettings(
            accessToken: 'token',
            username: 'user',
            syncEnabled: false,
        );
        $plugin = $this->makePlugin($repo, null, $settings);

        $event = new PlaybackStopped('session-1', 'user-1', 'm1', 'device-1', 0, true);
        $plugin->onPlaybackStopped($event);

        $this->assertTrue(true);
    }

    public function testOnPlaybackStoppedSkipsWhenNotReachedEnd(): void
    {
        $repo = new ItemRepository();
        $settings = new AniListSettings(
            accessToken: 'token',
            username: 'user',
            syncEnabled: true,
        );
        $plugin = $this->makePlugin($repo, null, $settings);

        $event = new PlaybackStopped('session-1', 'user-1', 'm1', 'device-1', 0, false);
        $plugin->onPlaybackStopped($event);

        $this->assertTrue(true);
    }

    public function testOnPlaybackStoppedSkipsWhenMediaItemNotFound(): void
    {
        $repo = new ItemRepository();
        $repo->rows['m1'] = [
            'id' => 'm1',
            'name' => 'Test Anime',
            'type' => 'episode',
            'path' => '/test.mkv',
            'metadata' => [
                'mal_id' => 1,
                'anilist_id' => 123,
                'episode_number' => 5,
            ],
        ];

        $settings = new AniListSettings(
            accessToken: 'token',
            username: 'user',
            syncEnabled: true,
        );

        // Create a plugin with no API set - if it tries to call API, it will fail
        $plugin = $this->makePlugin($repo, null, $settings);

        // Item doesn't exist
        $event = new PlaybackStopped('session-1', 'user-1', 'nonexistent', 'device-1', 0, true);
        $plugin->onPlaybackStopped($event);

        $this->assertTrue(true);
    }

    public function testOnPlaybackStoppedSkipsWhenNotEpisode(): void
    {
        $repo = new ItemRepository();
        $repo->rows['m1'] = [
            'id' => 'm1',
            'name' => 'Test Movie',
            'type' => 'movie',
            'path' => '/test.mkv',
            'metadata' => [
                'mal_id' => 1,
                'anilist_id' => 123,
            ],
        ];

        $settings = new AniListSettings(
            accessToken: 'token',
            username: 'user',
            syncEnabled: true,
        );
        $plugin = $this->makePlugin($repo, null, $settings);

        $event = new PlaybackStopped('session-1', 'user-1', 'm1', 'device-1', 0, true);
        $plugin->onPlaybackStopped($event);

        $this->assertTrue(true);
    }

    public function testOnPlaybackStoppedSkipsWhenNoEpisodeNumber(): void
    {
        $repo = new ItemRepository();
        $repo->rows['m1'] = [
            'id' => 'm1',
            'name' => 'Test Anime',
            'type' => 'episode',
            'path' => '/test.mkv',
            'metadata' => [
                'mal_id' => 1,
                'anilist_id' => 123,
                // no episode_number
            ],
        ];

        $settings = new AniListSettings(
            accessToken: 'token',
            username: 'user',
            syncEnabled: true,
        );
        $plugin = $this->makePlugin($repo, null, $settings);

        $event = new PlaybackStopped('session-1', 'user-1', 'm1', 'device-1', 0, true);
        $plugin->onPlaybackStopped($event);

        $this->assertTrue(true);
    }

    public function testOnPlaybackStoppedSkipsWhenNoAniListId(): void
    {
        $repo = new ItemRepository();
        $repo->rows['m1'] = [
            'id' => 'm1',
            'name' => 'Test Anime',
            'type' => 'episode',
            'path' => '/test.mkv',
            'metadata' => [
                'mal_id' => 1,
                // no anilist_id
                'episode_number' => 5,
            ],
        ];

        $settings = new AniListSettings(
            accessToken: 'token',
            username: 'user',
            syncEnabled: true,
        );
        $plugin = $this->makePlugin($repo, null, $settings);

        $event = new PlaybackStopped('session-1', 'user-1', 'm1', 'device-1', 0, true);
        $plugin->onPlaybackStopped($event);

        $this->assertTrue(true);
    }

    public function testOnPlaybackStoppedSyncsProgressWhenAllConditionsMet(): void
    {
        $repo = new ItemRepository();
        $repo->rows['m1'] = [
            'id' => 'm1',
            'name' => 'Test Anime',
            'type' => 'episode',
            'path' => '/test.mkv',
            'metadata' => [
                'mal_id' => 1,
                'anilist_id' => 123,
                'episode_number' => 5,
            ],
        ];

        $settings = new AniListSettings(
            accessToken: 'token',
            username: 'user',
            syncEnabled: true,
        );

        // Create a mock API that records calls
        $apiCalled = false;
        $api = new class ($apiCalled) extends AniListApi {
            public bool $wasCalled = false;

            public function __construct(bool &$flag)
            {
                $http = new class implements HttpClientInterface {
                    public function post(string $url, array $data, array $headers = []): array
                    {
                        return ['data' => ['SaveMediaListEntry' => []]];
                    }

                    public function get(string $url, array $params = [], array $headers = []): array
                    {
                        return [];
                    }
                };
                parent::__construct($http, 'test-token', new NullLogger());
                $this->wasCalled =& $flag;
            }

            public function saveEpisodeProgress(int $mediaId, int $episode, ?int $score = null, ?string $status = null): array
            {
                $this->wasCalled = true;

                return [
                    'id' => 1,
                    'mediaId' => $mediaId,
                    'status' => 'CURRENT',
                    'score' => 0,
                    'progress' => $episode,
                ];
            }
        };

        $plugin = $this->makePlugin($repo, $api, $settings);

        $event = new PlaybackStopped('session-1', 'user-1', 'm1', 'device-1', 0, true);
        $plugin->onPlaybackStopped($event);

        $this->assertTrue($api->wasCalled, 'API should have been called to sync progress');
    }

    public function testEnrichMediaItemSkipsWhenItemNotFound(): void
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

        $api = new AniListApi($http, 'test-token', new NullLogger());
        $plugin = $this->makePlugin($repo, $api);

        // This should not throw
        $this->invoke($plugin, 'enrichMediaItem', 'nonexistent');

        $this->assertCount(0, $repo->updateCalls);
    }

    public function testEnrichMediaItemSkipsWhenNoExternalId(): void
    {
        $repo = new ItemRepository();
        $repo->rows['m1'] = [
            'id' => 'm1',
            'name' => 'Test',
            'type' => 'episode',
            'path' => '/x.mkv',
            'metadata' => [], // no mal_id
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

        $api = new AniListApi($http, 'test-token', new NullLogger());
        $plugin = $this->makePlugin($repo, $api);

        $this->invoke($plugin, 'enrichMediaItem', 'm1');

        $this->assertCount(0, $repo->updateCalls);
    }

    public function testEnrichMediaItemSkipsWhenApiReturnsNull(): void
    {
        $repo = new ItemRepository();
        $repo->rows['m1'] = [
            'id' => 'm1',
            'name' => 'Test',
            'type' => 'episode',
            'path' => '/x.mkv',
            'metadata' => ['mal_id' => 1],
        ];

        $http = new class (null) implements HttpClientInterface {
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

        $api = new AniListApi($http, 'test-token', new NullLogger());
        $plugin = $this->makePlugin($repo, $api);

        $this->invoke($plugin, 'enrichMediaItem', 'm1');

        $this->assertCount(0, $repo->updateCalls);
    }

    public function testUpdateMediaItemMetadataSkipsWhenRepositoryNull(): void
    {
        $repo = new ItemRepository();
        $ref = new \ReflectionClass(AniListPlugin::class);
        /** @var AniListPlugin $plugin */
        $plugin = $ref->newInstanceWithoutConstructor();
        $this->setProp($plugin, 'itemRepository', null);
        $this->setProp($plugin, 'logger', new NullLogger());

        // This should not throw
        $this->invoke($plugin, 'updateMediaItemMetadata', 'm1', ['foo' => 'bar']);

        $this->assertTrue(true);
    }

    public function testBuildMetadataFromEntryHandlesAllFields(): void
    {
        $repo = new ItemRepository();
        $plugin = $this->makePlugin($repo);

        $entry = [
            'id' => 1,
            'idMal' => 2,
            'title' => [
                'romaji' => 'Romaji Title',
                'english' => 'English Title',
                'native' => 'Native Title',
            ],
            'description' => '<p>Test description</p>with html tags',
            'averageScore' => 85,
            'popularity' => 1000,
            'favourites' => 500,
            'genres' => ['Action', 'Drama'],
            'format' => 'TV',
            'status' => 'FINISHED',
            'episodes' => 12,
            'duration' => 24,
            'chapters' => 0,
            'volumes' => 0,
            'season' => 'WINTER',
            'seasonYear' => 2024,
            'coverImage' => [
                'large' => 'https://example.com/large.jpg',
                'extraLarge' => 'https://example.com/extralarge.jpg',
            ],
            'bannerImage' => 'https://example.com/banner.jpg',
            'startDate' => ['year' => 2024, 'month' => 1, 'day' => 1],
            'endDate' => ['year' => 2024, 'month' => 3, 'day' => 31],
            'nextAiringEpisode' => ['episode' => 5, 'airingAt' => 1700000000],
        ];

        $result = $this->invoke($plugin, 'buildMetadataFromEntry', $entry);

        $this->assertSame(1, $result['anilist_id']);
        $this->assertSame(2, $result['mal_id']);
        $this->assertSame('Romaji Title', $result['title_romaji']);
        $this->assertSame('English Title', $result['title_english']);
        $this->assertSame('Native Title', $result['title_native']);
        $this->assertSame('Test descriptionwith html tags', $result['description']);
        $this->assertSame(85, $result['average_score']);
        $this->assertSame(1000, $result['popularity']);
        $this->assertSame(500, $result['favourites']);
        $this->assertSame(['Action', 'Drama'], $result['genres']);
        $this->assertSame('TV', $result['format']);
        $this->assertSame('FINISHED', $result['status']);
        $this->assertSame(12, $result['episodes']);
        $this->assertSame(24, $result['duration']);
        $this->assertSame(0, $result['chapters']);
        $this->assertSame(0, $result['volumes']);
        $this->assertSame('WINTER', $result['season']);
        $this->assertSame(2024, $result['season_year']);
        $this->assertSame('https://example.com/large.jpg', $result['cover_image_large']);
        $this->assertSame('https://example.com/extralarge.jpg', $result['cover_image_extralarge']);
        $this->assertSame('https://example.com/banner.jpg', $result['banner_image']);
        $this->assertSame(['year' => 2024, 'month' => 1, 'day' => 1], $result['start_date']);
        $this->assertSame(['year' => 2024, 'month' => 3, 'day' => 31], $result['end_date']);
        $this->assertSame(['episode' => 5, 'airingAt' => 1700000000], $result['next_airing_episode']);
    }

    public function testBuildMetadataFromEntryHandlesMissingFields(): void
    {
        $repo = new ItemRepository();
        $plugin = $this->makePlugin($repo);

        $entry = [
            'id' => 1,
            // most fields missing
        ];

        $result = $this->invoke($plugin, 'buildMetadataFromEntry', $entry);

        // id is set so anilist_id should be 1, but mal_id should be null (no idMal)
        $this->assertSame(1, $result['anilist_id']);
        $this->assertNull($result['mal_id']);
        $this->assertNull($result['title_romaji']);
        $this->assertNull($result['description']);
        $this->assertSame([], $result['genres']);
    }

    public function testBuildMetadataFromEntryHandlesNonStringDescription(): void
    {
        $repo = new ItemRepository();
        $plugin = $this->makePlugin($repo);

        $entry = [
            'id' => 1,
            'description' => null,
        ];

        $result = $this->invoke($plugin, 'buildMetadataFromEntry', $entry);

        $this->assertNull($result['description']);
    }

    public function testGapFillMergeDoesNotClobberExistingValues(): void
    {
        $repo = new ItemRepository();
        $plugin = $this->makePlugin($repo);

        $existing = [
            'title_romaji' => 'Existing Title',
            'mal_id' => 1,
            'some_field' => 'preserve this',
        ];

        $incoming = [
            'title_romaji' => 'New Title',
            'mal_id' => 2,
            'anilist_id' => 99,
        ];

        $result = $this->invoke($plugin, 'gapFillMerge', $existing, $incoming);

        $this->assertSame('Existing Title', $result['title_romaji']);
        $this->assertSame(1, $result['mal_id']);
        $this->assertSame('preserve this', $result['some_field']);
        $this->assertSame(99, $result['anilist_id']);
    }

    public function testGapFillMergeFillsEmptyValues(): void
    {
        $repo = new ItemRepository();
        $plugin = $this->makePlugin($repo);

        $existing = [
            'title_romaji' => '',
            'mal_id' => 1,
        ];

        $incoming = [
            'title_romaji' => 'New Title',
            'anilist_id' => 99,
        ];

        $result = $this->invoke($plugin, 'gapFillMerge', $existing, $incoming);

        $this->assertSame('New Title', $result['title_romaji']);
        $this->assertSame(1, $result['mal_id']);
        $this->assertSame(99, $result['anilist_id']);
    }

    public function testGapFillMergeSkipsNullAndEmptyArrayValues(): void
    {
        $repo = new ItemRepository();
        $plugin = $this->makePlugin($repo);

        $existing = [
            'anilist_id' => 5,
        ];

        $incoming = [
            'anilist_id' => null,
            'genres' => [],
            'new_field' => 'value',
        ];

        $result = $this->invoke($plugin, 'gapFillMerge', $existing, $incoming);

        // Existing non-empty value is preserved
        $this->assertSame(5, $result['anilist_id']);
        // Empty array values don't contribute (isEmptyValue([]) is true)
        $this->assertArrayNotHasKey('genres', $result);
        // New non-empty values are added
        $this->assertSame('value', $result['new_field']);
    }

    public function testIsEmptyValueCorrectlyIdentifiesEmptyValues(): void
    {
        $repo = new ItemRepository();
        $plugin = $this->makePlugin($repo);

        $this->assertTrue($this->invoke($plugin, 'isEmptyValue', null));
        $this->assertTrue($this->invoke($plugin, 'isEmptyValue', ''));
        $this->assertTrue($this->invoke($plugin, 'isEmptyValue', []));

        $this->assertFalse($this->invoke($plugin, 'isEmptyValue', 0));
        $this->assertFalse($this->invoke($plugin, 'isEmptyValue', '0'));
        $this->assertFalse($this->invoke($plugin, 'isEmptyValue', 'hello'));
        $this->assertFalse($this->invoke($plugin, 'isEmptyValue', [1, 2, 3]));
        $this->assertFalse($this->invoke($plugin, 'isEmptyValue', false));
    }

    public function testFindExternalIdReturnsMalId(): void
    {
        $repo = new ItemRepository();
        $plugin = $this->makePlugin($repo);

        $mediaItem = new \Phlix\Media\Library\MediaItem(
            id: 'm1',
            name: 'Test',
            type: 'anime',
            path: '/x.mkv',
            metadata: ['mal_id' => 12345],
        );

        $result = $this->invoke($plugin, 'findExternalId', $mediaItem);

        $this->assertSame(12345, $result);
    }

    public function testFindExternalIdReturnsNullWhenNoMalId(): void
    {
        $repo = new ItemRepository();
        $plugin = $this->makePlugin($repo);

        $mediaItem = new \Phlix\Media\Library\MediaItem(
            id: 'm1',
            name: 'Test',
            type: 'anime',
            path: '/x.mkv',
            metadata: [],
        );

        $result = $this->invoke($plugin, 'findExternalId', $mediaItem);

        $this->assertNull($result);
    }

    public function testLookupAniListEntryReturnsNullWhenApiNotSet(): void
    {
        $repo = new ItemRepository();
        $ref = new \ReflectionClass(AniListPlugin::class);
        /** @var AniListPlugin $plugin */
        $plugin = $ref->newInstanceWithoutConstructor();
        $this->setProp($plugin, 'settings', new AniListSettings(accessToken: 'token', username: 'user'));
        $this->setProp($plugin, 'enabled', true);
        $this->setProp($plugin, 'api', null);
        $this->setProp($plugin, 'logger', new NullLogger());

        $result = $this->invoke($plugin, 'lookupAniListEntry', 123, 'anime');

        $this->assertNull($result);
    }

    public function testLookupAniListEntryReturnsEntryOnSuccess(): void
    {
        $repo = new ItemRepository();
        $settings = new AniListSettings(accessToken: 'token', username: 'user');

        $entry = ['id' => 123, 'title' => ['romaji' => 'Test']];
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
        $plugin = $this->makePlugin($repo, $api, $settings);

        $result = $this->invoke($plugin, 'lookupAniListEntry', 1, 'anime');

        $this->assertNotNull($result);
        $this->assertSame(123, $result['id']);
    }

    public function testLookupAniListEntryReturnsNullOnApiException(): void
    {
        $repo = new ItemRepository();
        $settings = new AniListSettings(accessToken: 'token', username: 'user');

        $http = new class implements HttpClientInterface {
            public function post(string $url, array $data, array $headers = []): array
            {
                return ['errors' => [['message' => 'Error']]];
            }

            public function get(string $url, array $params = [], array $headers = []): array
            {
                return [];
            }
        };

        $api = new AniListApi($http, 'token', new NullLogger());
        $plugin = $this->makePlugin($repo, $api, $settings);

        $result = $this->invoke($plugin, 'lookupAniListEntry', 1, 'anime');

        $this->assertNull($result);
    }

    public function testEnsureApiDoesNothingWhenApiAlreadySet(): void
    {
        $repo = new ItemRepository();
        $settings = new AniListSettings(accessToken: 'token', username: 'user');

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

        $existingApi = new AniListApi($http, 'token', new NullLogger());
        $plugin = $this->makePlugin($repo, $existingApi, $settings);

        // This should not throw
        $this->invoke($plugin, 'ensureApi');

        // API should still be the same instance
        $this->assertSame($existingApi, $this->getProp($plugin, 'api'));
    }

    public function testEnsureApiDoesNothingWhenNoAccessToken(): void
    {
        $repo = new ItemRepository();
        $settings = new AniListSettings(accessToken: '', username: 'user');

        $plugin = $this->makePlugin($repo, null, $settings);

        // This should not throw
        $this->invoke($plugin, 'ensureApi');

        // API should still be null
        $this->assertNull($this->getProp($plugin, 'api'));
    }

    public function testFindMediaItemReturnsNullWhenRepositoryNull(): void
    {
        $ref = new \ReflectionClass(AniListPlugin::class);
        /** @var AniListPlugin $plugin */
        $plugin = $ref->newInstanceWithoutConstructor();
        $this->setProp($plugin, 'itemRepository', null);
        $this->setProp($plugin, 'logger', new NullLogger());

        $result = $this->invoke($plugin, 'findMediaItem', 'm1');

        $this->assertNull($result);
    }

    public function testFindMediaItemReturnsNullWhenItemNotFound(): void
    {
        $repo = new ItemRepository();
        $plugin = $this->makePlugin($repo);

        $result = $this->invoke($plugin, 'findMediaItem', 'nonexistent');

        $this->assertNull($result);
    }

    public function testFindMediaItemReturnsMediaItem(): void
    {
        $repo = new ItemRepository();
        $repo->rows['m1'] = [
            'id' => 'm1',
            'name' => 'Test Anime',
            'type' => 'anime',
            'path' => '/test.mkv',
            'metadata' => ['mal_id' => 1],
        ];

        $plugin = $this->makePlugin($repo);

        $result = $this->invoke($plugin, 'findMediaItem', 'm1');

        $this->assertNotNull($result);
        $this->assertSame('m1', $result->id);
        $this->assertSame('Test Anime', $result->name);
    }

    public function testEnqueueForEnrichmentDropsItemWhenQueueFull(): void
    {
        $repo = new ItemRepository();
        $plugin = $this->makePlugin($repo);

        // Fill the queue
        $maxQueue = 5000;
        $filledQueue = [];
        for ($i = 0; $i < $maxQueue; $i++) {
            $filledQueue[] = 'item_' . $i;
        }
        $this->setProp($plugin, 'enrichQueue', $filledQueue);

        // Try to enqueue one more
        $plugin->onMediaItemAdded(new \Phlix\Shared\Events\Library\MediaItemAdded('overflow_item', 'lib', '/x.mkv', 'episode'));

        // Queue should still be full (not grow beyond MAX_ENRICH_QUEUE)
        $queue = $this->getProp($plugin, 'enrichQueue');
        $this->assertCount($maxQueue, $queue);
        $this->assertNotContains('overflow_item', $queue);
    }
}
