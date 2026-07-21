<?php

/**
 * AniList Plugin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Metadata\AniList;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MediaItem;
use Phlix\Shared\Plugin\ConfigurableInterface;
use Phlix\Shared\Plugin\LifecycleInterface;
use Phlix\Shared\Events\Library\LibraryScanCompleted;
use Phlix\Shared\Events\Playback\PlaybackStopped;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * AniList metadata provider plugin entry class.
 *
 * Subscribes to LibraryScanCompleted and PlaybackStopped events to:
 * - Enrich media items with AniList metadata (titles, descriptions, scores, covers)
 * - Sync watchlist status (watching/watched/plantowatch) from AniList
 * - Track episode progress on completion
 *
 * @package Phlix\Plugins\Metadata\AniList
 * @since 0.14.0
 */
final class AniListPlugin implements LifecycleInterface, ConfigurableInterface
{
    /**
     * Plugin type identifier used in the plugin manifest.
     */
    public const PLUGIN_TYPE = 'metadata-provider';

    /**
     * Interval for periodic watchlist sync (60 minutes).
     */
    private const SYNC_INTERVAL_SEC = 3600;

    private ?ItemRepository $itemRepository = null;
    private ?LoggerInterface $logger = null;
    private ?AniListApi $api = null;

    /** Disables all metadata enrichment and sync when false. */
    private bool $enabled = false;

    /** User-specific settings (tokens, username, prefs). */
    private AniListSettings $settings;

    /**
     * @param AniListSettings|null $settings Initial settings (loaded from DB on enable)
     * @param LoggerInterface|null $logger Optional PSR-3 logger
     * @param AniListApi|null $api Pre-built API client (test seam)
     */
    public function __construct(
        ?AniListSettings $settings = null,
        ?LoggerInterface $logger = null,
        ?AniListApi $api = null,
    ) {
        $this->settings = $settings ?? new AniListSettings();
        $this->logger = $logger ?? new NullLogger();
        $this->api = $api;
    }

    /**
     * Configure the plugin from a settings array (persisted in the DB
     * by the plugin loader and passed back on enable).
     *
     * @param array<string, mixed> $settings Key-value settings from plugins.settings_json
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function configure(array $settings): void
    {
        $this->settings = AniListSettings::fromArray($settings);
        $this->enabled = ($settings['enabled'] ?? false) === true;
    }

    /**
     * @param ContainerInterface $container Host PSR-11 container
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function onEnable(ContainerInterface $container): void
    {
        if ($this->logger instanceof NullLogger) {
            $logger = $container->get(LoggerInterface::class);
            $this->logger = $logger instanceof LoggerInterface ? $logger : new NullLogger();
        }

        $itemRepo = $container->get(ItemRepository::class);
        $this->itemRepository = $itemRepo instanceof ItemRepository ? $itemRepo : null;

        $this->initApi();

        if ($this->settings->syncEnabled) {
            $this->schedulePeriodicSync($container);
        }
    }

    /**
     * Release resources on disable.
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function onDisable(): void
    {
        $this->itemRepository = null;
    }

    /**
     * Return the event subscriptions for this plugin.
     *
     * @return array<class-string, string|callable>
     *
     * @since 0.14.0
     */
    public function subscribedEvents(): array
    {
        return [
            LibraryScanCompleted::class => 'onLibraryScanCompleted',
            PlaybackStopped::class => 'onPlaybackStopped',
        ];
    }

    /**
     * Handle library scan completion — attempt to enrich items with AniList metadata.
     *
     * @param LibraryScanCompleted $event The library scan completed event
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function onLibraryScanCompleted(LibraryScanCompleted $event): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        if (!$this->settings->autoMatch) {
            return;
        }

        $this->logger?->info('AniList: starting metadata enrichment after library scan', [
            'profile_id' => $event->profileId,
            'item_count' => count($event->mediaItemIds),
        ]);

        foreach ($event->mediaItemIds as $mediaItemId) {
            $this->enrichMediaItem($mediaItemId);
        }
    }

    /**
     * Handle playback stop — sync episode progress to AniList if completed.
     *
     * @param PlaybackStopped $event The playback stopped event
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function onPlaybackStopped(PlaybackStopped $event): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        if (!$this->settings->syncEnabled) {
            return;
        }

        if (!$event->reachedEnd) {
            return;
        }

        $mediaItem = $this->findMediaItem($event->mediaItemId);
        if ($mediaItem === null) {
            return;
        }

        if ($mediaItem->type !== 'episode') {
            return;
        }

        $episodeNumber = $mediaItem->metadata['episode_number'] ?? null;
        if (!is_int($episodeNumber)) {
            return;
        }

        $anilistId = $mediaItem->metadata['anilist_id'] ?? null;
        if (!is_int($anilistId)) {
            $this->logger?->debug('AniList: no anilist_id on media item, skipping progress sync', [
                'media_item_id' => $event->mediaItemId,
            ]);
            return;
        }

        $this->syncProgressToAniList($anilistId, $episodeNumber);
    }

    /**
     * Enrich a media item with AniList metadata.
     *
     * @param string $mediaItemId Media item UUID
     *
     * @return void
     */
    private function enrichMediaItem(string $mediaItemId): void
    {
        $mediaItem = $this->findMediaItem($mediaItemId);
        if ($mediaItem === null) {
            return;
        }

        $externalId = $this->findExternalId($mediaItem);
        if ($externalId === null) {
            $this->logger?->debug('AniList: no external ID found for media item', [
                'media_item_id' => $mediaItemId,
                'name' => $mediaItem->name,
            ]);
            return;
        }

        $anilistEntry = $this->lookupAniListEntry($externalId, $mediaItem->type);
        if ($anilistEntry === null) {
            $this->logger?->debug('AniList: no entry found for external ID', [
                'media_item_id' => $mediaItemId,
                'external_id' => $externalId,
            ]);
            return;
        }

        $metadata = $this->buildMetadataFromEntry($anilistEntry);
        $this->updateMediaItemMetadata($mediaItemId, $metadata);

        $this->logger?->info('AniList: enriched media item', [
            'media_item_id' => $mediaItemId,
            'anilist_id' => $anilistEntry['id'] ?? null,
            'title' => $anilistEntry['title']['romaji'] ?? 'unknown',
        ]);
    }

    /**
     * Find an external ID (MyAnimeList ID) from a media item.
     *
     * @param MediaItem $mediaItem The media item to check
     *
     * @return int|null The MAL ID if found
     */
    private function findExternalId(MediaItem $mediaItem): ?int
    {
        $malId = $mediaItem->metadata['mal_id'] ?? null;
        if (is_int($malId)) {
            return $malId;
        }

        return null;
    }

    /**
     * Look up an entry on AniList by external ID.
     *
     * @param int $externalId MyAnimeList ID or AniList ID
     * @param string $type Media type (anime/manga)
     *
     * @return array<string, mixed>|null The AniList entry or null
     */
    private function lookupAniListEntry(int $externalId, string $type): ?array
    {
        if ($this->api === null) {
            return null;
        }

        try {
            $entry = $this->api->getMediaByMalId($externalId);
            if ($entry !== null) {
                return $entry;
            }
        } catch (AniListApiException $e) {
            $this->logger?->warning('AniList: failed to look up entry by MAL ID', [
                'mal_id' => $externalId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Build a metadata array from an AniList entry.
     *
     * @param array<string, mixed> $entry AniList media entry
     *
     * @return array<string, mixed> Metadata key-value array
     */
    private function buildMetadataFromEntry(array $entry): array
    {
        $title = $entry['title'] ?? [];
        $coverImage = $entry['coverImage'] ?? [];

        return [
            'anilist_id' => is_int($entry['id'] ?? null) ? $entry['id'] : null,
            'mal_id' => is_int($entry['idMal'] ?? null) ? $entry['idMal'] : null,
            'title_romaji' => is_string($title['romaji'] ?? null) ? $title['romaji'] : null,
            'title_english' => is_string($title['english'] ?? null) ? $title['english'] : null,
            'title_native' => is_string($title['native'] ?? null) ? $title['native'] : null,
            'description' => is_string($entry['description'] ?? null)
                ? mb_substr(preg_replace('/<[^>]+>/', '', $entry['description']), 0, 2000)
                : null,
            'average_score' => is_int($entry['averageScore'] ?? null) ? $entry['averageScore'] : null,
            'popularity' => is_int($entry['popularity'] ?? null) ? $entry['popularity'] : null,
            'favourites' => is_int($entry['favourites'] ?? null) ? $entry['favourites'] : null,
            'genres' => is_array($entry['genres'] ?? null) ? $entry['genres'] : [],
            'format' => is_string($entry['format'] ?? null) ? $entry['format'] : null,
            'status' => is_string($entry['status'] ?? null) ? $entry['status'] : null,
            'episodes' => is_int($entry['episodes'] ?? null) ? $entry['episodes'] : null,
            'duration' => is_int($entry['duration'] ?? null) ? $entry['duration'] : null,
            'chapters' => is_int($entry['chapters'] ?? null) ? $entry['chapters'] : null,
            'volumes' => is_int($entry['volumes'] ?? null) ? $entry['volumes'] : null,
            'season' => is_string($entry['season'] ?? null) ? $entry['season'] : null,
            'season_year' => is_int($entry['seasonYear'] ?? null) ? $entry['seasonYear'] : null,
            'cover_image_large' => is_string($coverImage['large'] ?? null) ? $coverImage['large'] : null,
            'cover_image_extralarge' => is_string($coverImage['extraLarge'] ?? null) ? $coverImage['extraLarge'] : null,
            'banner_image' => is_string($entry['bannerImage'] ?? null) ? $entry['bannerImage'] : null,
            'start_date' => $entry['startDate'] ?? null,
            'end_date' => $entry['endDate'] ?? null,
            'next_airing_episode' => $entry['nextAiringEpisode'] ?? null,
        ];
    }

    /**
     * Update a media item's metadata in the repository.
     *
     * @param string $mediaItemId Media item UUID
     * @param array<string, mixed> $metadata New metadata to merge
     *
     * @return void
     */
    private function updateMediaItemMetadata(string $mediaItemId, array $metadata): void
    {
        if ($this->itemRepository === null) {
            return;
        }

        try {
            $row = $this->itemRepository->findById($mediaItemId);
            if ($row === null) {
                return;
            }

            $existingMetadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
            $updatedMetadata = array_merge($existingMetadata, $metadata);
            $this->itemRepository->updateMetadata($mediaItemId, $updatedMetadata);
        } catch (\Throwable $e) {
            $this->logger?->warning('AniList: failed to update media item metadata', [
                'media_item_id' => $mediaItemId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sync episode progress to AniList.
     *
     * @param int $anilistId AniList media ID
     * @param int $episodeNumber Episode number completed
     *
     * @return void
     */
    private function syncProgressToAniList(int $anilistId, int $episodeNumber): void
    {
        if ($this->api === null) {
            return;
        }

        try {
            $result = $this->api->saveEpisodeProgress($anilistId, $episodeNumber);

            $this->logger?->info('AniList: synced episode progress', [
                'anilist_id' => $anilistId,
                'episode' => $episodeNumber,
                'result' => $result,
            ]);
        } catch (AniListApiException $e) {
            $this->logger?->warning('AniList: failed to sync episode progress', [
                'anilist_id' => $anilistId,
                'episode' => $episodeNumber,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Whether the plugin has all required configuration.
     *
     * @return bool
     */
    private function isConfigured(): bool
    {
        return $this->enabled && $this->settings->isConfigured() && $this->api !== null;
    }

    /**
     * Initialize the AniList API client from current settings.
     *
     * @return void
     */
    private function initApi(): void
    {
        if ($this->api !== null) {
            return;
        }

        $config = $this->loadConfig();

        $accessToken = is_string($config['access_token'] ?? null) ? $config['access_token'] : '';
        if ($accessToken === '' && $this->settings->accessToken !== null) {
            $accessToken = $this->settings->accessToken;
        }

        if ($accessToken !== '') {
            $this->api = new AniListApi(
                new HttpClient($this->logger),
                $accessToken,
                $this->logger
            );
        }
    }

    /**
     * Schedule periodic watchlist sync.
     *
     * @param ContainerInterface $container Host PSR-11 container
     *
     * @return void
     */
    /**
     * Resolve the configured sync interval, in seconds.
     *
     * The `sync_interval_minutes` setting was parsed into
     * {@see AniListSettings::$syncIntervalMinutes} and then IGNORED — the timer
     * used the hardcoded {@see self::SYNC_INTERVAL_SEC} constant, so changing the
     * setting in the admin UI did nothing. This makes it real.
     *
     * Clamped to a one-minute floor: the value reaches us from admin input, and a
     * zero or negative interval would arm a runaway timer inside a resident
     * Workerman worker.
     */
    private function syncIntervalSeconds(): int
    {
        $minutes = $this->settings->syncIntervalMinutes;

        return $minutes >= 1 ? $minutes * 60 : self::SYNC_INTERVAL_SEC;
    }

    private function schedulePeriodicSync(ContainerInterface $container): void
    {
        if (!$this->settings->isConfigured()) {
            return;
        }

        try {
            \Workerman\Timer::add($this->syncIntervalSeconds(), function (): void {
                $this->runScheduledSync();
            });
        } catch (\Throwable) {
            // Timer not available outside Workerman process
        }
    }

    /**
     * Execute the periodic watchlist sync.
     *
     * @return void
     */
    private function runScheduledSync(): void
    {
        if (!$this->settings->isConfigured() || !$this->settings->syncEnabled) {
            return;
        }

        try {
            $this->syncWatchlistFromAniList();
        } catch (\Throwable $e) {
            $this->logger?->warning('AniList periodic sync failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sync watchlist status from AniList to Phlix.
     *
     * @return void
     */
    private function syncWatchlistFromAniList(): void
    {
        if ($this->api === null || $this->itemRepository === null) {
            return;
        }

        $username = $this->settings->username;
        if ($username === '') {
            return;
        }

        $this->logger?->info('AniList: starting watchlist sync', [
            'username' => $username,
        ]);

        $statuses = ['CURRENT', 'COMPLETED', 'PLANNING', 'PAUSED', 'DROPPED'];
        foreach ($statuses as $status) {
            $this->syncStatusList($username, $status);
        }

        $this->logger?->info('AniList: watchlist sync completed', [
            'username' => $username,
        ]);
    }

    /**
     * Sync a specific status list from AniList.
     *
     * @param string $username AniList username
     * @param string $status Status to sync
     *
     * @return void
     */
    private function syncStatusList(string $username, string $status): void
    {
        if ($this->api === null) {
            return;
        }

        try {
            $entries = $this->api->getUserAnimeList($username, $status, 1, 50);

            foreach ($entries as $entry) {
                $media = $entry['media'] ?? null;
                if (!is_array($media)) {
                    continue;
                }

                $malId = is_int($media['idMal'] ?? null) ? $media['idMal'] : null;
                if ($malId === null) {
                    continue;
                }

                $anilistId = is_int($media['id'] ?? null) ? $media['id'] : null;
                $this->updateWatchlistStatus($malId, $anilistId, $status, $entry);
            }
        } catch (AniListApiException $e) {
            $this->logger?->warning('AniList: failed to sync status list', [
                'username' => $username,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update watchlist status for a media item.
     *
     * @param int $malId MyAnimeList ID
     * @param int|null $anilistId AniList ID
     * @param string $status AniList status
     * @param array<string, mixed> $entry Full list entry from AniList
     *
     * @return void
     */
    private function updateWatchlistStatus(int $malId, ?int $anilistId, string $status, array $entry): void
    {
        if ($this->itemRepository === null) {
            return;
        }

        $metadata = [
            'anilist_id' => $anilistId,
            'anilist_status' => $status,
            'anilist_score' => is_int($entry['score'] ?? null) ? $entry['score'] : null,
            'anilist_progress' => is_int($entry['progress'] ?? null) ? $entry['progress'] : null,
            'anilist_favourite' => false,
        ];

        try {
            $this->itemRepository->updateMetadataByExternalId('mal_id', (string) $malId, $metadata);
        } catch (\Throwable $e) {
            $this->logger?->debug('AniList: failed to update watchlist status', [
                'mal_id' => $malId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Look up a media item by ID.
     *
     * @param string $mediaItemId Media item UUID
     *
     * @return MediaItem|null
     */
    private function findMediaItem(string $mediaItemId): ?MediaItem
    {
        if ($this->itemRepository === null) {
            return null;
        }

        $row = $this->itemRepository->findById($mediaItemId);
        if ($row === null) {
            return null;
        }

        return MediaItem::fromRow($row);
    }

    /**
     * Load AniList plugin configuration.
     *
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        $configFile = dirname(__DIR__, 5) . '/config/metadata/anilist.php';

        if (is_file($configFile)) {
            /** @var array<string, mixed> $config */
            $config = include $configFile;

            return $config;
        }

        return [];
    }

    /**
     * Get the current settings.
     *
     * @return AniListSettings
     *
     * @since 0.14.0
     */
    public function getSettings(): AniListSettings
    {
        return $this->settings;
    }

    /**
     * Get a REDACTED settings projection safe to return to the admin SPA.
     *
     * @return array<string, mixed>
     *
     * @since 0.14.0
     */
    public function getSettingsForSpa(): array
    {
        return $this->settings->toSpaArray();
    }
}
