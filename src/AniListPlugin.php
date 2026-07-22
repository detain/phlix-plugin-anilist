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
use Phlix\Shared\Events\Library\MediaItemAdded;
use Phlix\Shared\Events\Playback\PlaybackStopped;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * AniList metadata provider plugin entry class.
 *
 * Subscribes to MediaItemAdded and PlaybackStopped events to:
 * - Enrich media items with AniList metadata (titles, descriptions, scores, covers)
 * - Sync watchlist status (watching/watched/plantowatch) from AniList
 * - Track episode progress on completion
 *
 * Enrichment is never performed inline in the event handler — each new item is
 * pushed onto a bounded in-worker queue drained by a throttled one-shot timer
 * (one item per {@see self::ENRICH_INTERVAL_SEC}) so a large scan cannot flood
 * AniList's ~90 req/min budget. All network I/O flows through the non-blocking
 * {@see HttpClient}.
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

    /**
     * Throttle between enrichment lookups, in seconds. One item per second is
     * 60 req/min, comfortably below AniList's ~90 req/min limit.
     */
    private const ENRICH_INTERVAL_SEC = 1;

    /**
     * Hard cap on the enrichment backlog so a runaway scan cannot grow the queue
     * without bound inside a resident worker.
     */
    private const MAX_ENRICH_QUEUE = 5000;

    private ?ItemRepository $itemRepository = null;
    private ?LoggerInterface $logger = null;
    private ?AniListApi $api = null;

    /**
     * Bounded FIFO of media item IDs awaiting enrichment.
     *
     * @var list<string>
     */
    private array $enrichQueue = [];

    /** Whether a drain timer is currently armed (prevents double-arming). */
    private bool $drainArmed = false;

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
        // WIRE step — cheap, no network, must not throw at boot (~14 workers).
        // Only pulls collaborators out of the container and arms a timer; the
        // AniList API client and any HTTP are deferred to first use (the LAZY
        // "connect" step, {@see ensureApi()}).
        try {
            if ($this->logger instanceof NullLogger) {
                $logger = $container->get(LoggerInterface::class);
                $this->logger = $logger instanceof LoggerInterface ? $logger : new NullLogger();
            }

            $itemRepo = $container->get(ItemRepository::class);
            $this->itemRepository = $itemRepo instanceof ItemRepository ? $itemRepo : null;
        } catch (\Throwable $e) {
            // Never let a container miss take down worker boot.
            $this->logger?->warning('AniList: onEnable wiring failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // Arming a Workerman timer is non-blocking; the sync callback connects lazily.
        if ($this->settings->syncEnabled) {
            $this->schedulePeriodicSync();
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
            MediaItemAdded::class => 'onMediaItemAdded',
            PlaybackStopped::class => 'onPlaybackStopped',
        ];
    }

    /**
     * Handle a newly added media item — queue it for throttled enrichment.
     *
     * NOTE: this deliberately does NOT call AniList inline. `MediaItemAdded`
     * fires once per file during a scan, so enriching here would flood the API.
     * Instead the ID is enqueued and drained one item per
     * {@see self::ENRICH_INTERVAL_SEC} by {@see armDrainTimer()}.
     *
     * @param MediaItemAdded $event The media item added event
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function onMediaItemAdded(MediaItemAdded $event): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        if (!$this->settings->autoMatch) {
            return;
        }

        $this->enqueueForEnrichment($event->mediaItemId);
    }

    /**
     * Push a media item ID onto the bounded enrichment queue and ensure the
     * throttled drain timer is running.
     *
     * @param string $mediaItemId Media item UUID
     *
     * @return void
     */
    private function enqueueForEnrichment(string $mediaItemId): void
    {
        if (count($this->enrichQueue) >= self::MAX_ENRICH_QUEUE) {
            $this->logger?->warning('AniList: enrichment queue full, dropping item', [
                'media_item_id' => $mediaItemId,
                'queue_size' => count($this->enrichQueue),
            ]);
            return;
        }

        $this->enrichQueue[] = $mediaItemId;
        $this->armDrainTimer();
    }

    /**
     * Arm a one-shot, throttled timer that drains a single queued item and
     * re-arms itself while the queue is non-empty.
     *
     * A one-shot (re-arming) timer is used rather than a repeating one so the
     * worker isn't left with an idle tick firing forever once the backlog is
     * cleared (Workerman timers repeat by default).
     *
     * @return void
     */
    private function armDrainTimer(): void
    {
        if ($this->drainArmed) {
            return;
        }

        try {
            \Workerman\Timer::add(self::ENRICH_INTERVAL_SEC, function (): void {
                $this->drainArmed = false;
                $this->drainOne();
                if ($this->enrichQueue !== []) {
                    $this->armDrainTimer();
                }
            }, [], false);
            $this->drainArmed = true;
        } catch (\Throwable) {
            // Timer unavailable (not inside a Workerman worker). Leave the items
            // queued rather than draining inline; events are only dispatched in
            // resident workers where the timer is available.
            $this->logger?->debug('AniList: enrichment timer unavailable, deferring drain');
        }
    }

    /**
     * Drain and enrich a single queued media item.
     *
     * @return void
     */
    private function drainOne(): void
    {
        $mediaItemId = array_shift($this->enrichQueue);
        if ($mediaItemId === null) {
            return;
        }

        $this->ensureApi();
        $this->enrichMediaItem($mediaItemId);
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

            // findById() hydrates the JSON blob into the `metadata` key.
            $existingMetadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];

            // Gap-fill merge: AniList only fills fields the item does not already
            // have; it never clobbers an existing non-empty value written by a
            // higher-priority source (TMDB/IMDb).
            $mergedMetadata = $this->gapFillMerge($existingMetadata, $metadata);
            if ($mergedMetadata === $existingMetadata) {
                return; // nothing new to contribute
            }

            // The host ItemRepository::update() takes a column => value map; the
            // metadata blob lives in the `metadata_json` column (it is
            // json-encoded and the derived columns re-synced inside update()).
            $this->itemRepository->update($mediaItemId, ['metadata_json' => $mergedMetadata]);
        } catch (\Throwable $e) {
            // Now that the write actually reaches the DB, a failure is a real
            // fault — surface it at error level rather than swallowing it.
            $this->logger?->error('AniList: failed to persist media item metadata', [
                'media_item_id' => $mediaItemId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Merge new metadata into existing metadata WITHOUT clobbering existing
     * non-empty values (gap-fill semantics).
     *
     * @param array<string, mixed> $existing Current metadata blob
     * @param array<string, mixed> $incoming Candidate values from AniList
     *
     * @return array<string, mixed> Merged metadata
     */
    private function gapFillMerge(array $existing, array $incoming): array
    {
        $merged = $existing;

        foreach ($incoming as $key => $value) {
            if ($this->isEmptyValue($value)) {
                continue; // nothing to contribute
            }
            if (array_key_exists($key, $existing) && !$this->isEmptyValue($existing[$key])) {
                continue; // preserve the existing (higher-priority) value
            }
            $merged[$key] = $value;
        }

        return $merged;
    }

    /**
     * Whether a metadata value counts as "empty" for gap-fill purposes.
     *
     * @param mixed $value Value to test
     *
     * @return bool
     */
    private function isEmptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
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
        $this->ensureApi();
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
        return $this->enabled && $this->settings->isConfigured();
    }

    /**
     * Lazily build the AniList API client from the manually-configured personal
     * access token (the "connect" step, kept out of {@see onEnable()}).
     *
     * The token comes solely from the `access_token` plugin setting — AniList
     * OAuth is NOT implemented host-side, so there is no automatic token flow.
     * Constructing the client performs no network I/O.
     *
     * @return void
     */
    private function ensureApi(): void
    {
        if ($this->api !== null) {
            return;
        }

        $accessToken = $this->settings->accessToken ?? '';
        if ($accessToken === '') {
            return;
        }

        $this->api = new AniListApi(
            new HttpClient($this->logger),
            $accessToken,
            $this->logger ?? new NullLogger()
        );
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

    private function schedulePeriodicSync(): void
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

        $this->ensureApi();

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

        // The host has no "find a local item by an arbitrary metadata field"
        // method, so watchlist status cannot yet be mapped back to a local row.
        // Route through the same real update() seam once an ID is resolvable —
        // see resolveMediaItemIdByMalId() for the Wave 2 host-API gap.
        $mediaItemId = $this->resolveMediaItemIdByMalId($malId);
        if ($mediaItemId === null) {
            $this->logger?->debug(
                'AniList: cannot map MAL id to a local media item; watchlist status skipped '
                . '(needs a host lookup-by-metadata API — Wave 2)',
                ['mal_id' => $malId]
            );
            return;
        }

        // Watchlist fields are authoritative for the AniList namespace, so write
        // them straight through the same persistence path as enrichment.
        $this->updateMediaItemMetadata($mediaItemId, $metadata);
    }

    /**
     * Resolve a local media item ID from a MyAnimeList ID stored in the item's
     * metadata blob.
     *
     * WAVE 2 GAP: the host {@see ItemRepository} exposes no
     * find-by-metadata-field API (only findById/findByPath/findByIds/search),
     * so there is currently no way to locate the row whose
     * `metadata_json.mal_id` matches. Until the host provides one (e.g.
     * `ItemRepository::findByMetadataField('mal_id', $value): ?array`) this
     * returns null and the caller logs the skip — it never silently vanishes.
     *
     * @param int $malId MyAnimeList ID
     *
     * @return string|null Resolved media item UUID, or null when unresolvable
     */
    private function resolveMediaItemIdByMalId(int $malId): ?string
    {
        return null;
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
