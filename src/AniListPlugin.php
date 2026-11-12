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
 * - Sync watchlist status (CURRENT/COMPLETED/PLANNING/PAUSED/DROPPED; the
 *   rarely-used REPEATING status is deliberately not synced) from AniList
 * - Track episode progress on completion
 *
 * Enrichment is never performed inline in the event handler — each new item is
 * pushed onto a bounded in-worker queue drained by a throttled one-shot timer
 * (one item per {@see self::ENRICH_INTERVAL_SEC}) so a large scan cannot flood
 * AniList's ~90 req/min budget. All network I/O flows through the non-blocking
 * {@see HttpClient}.
 *
 * Timers are process-local, and that is load-bearing for sizing: the host
 * forks a pool of resident Workerman workers (~14) and {@see onEnable()}
 * runs in EVERY one of them, so each worker arms its own periodic sync
 * timer. The `sync_interval_minutes` setting is therefore a PER-WORKER
 * interval, not a server-global one — with N workers every tick performs N
 * full five-status page walks (up to {@see self::MAX_SYNC_PAGES} pages per
 * status each), multiplying the request pressure on AniList's ~90 req/min
 * budget by the worker count. This plugin has no cross-process coordination
 * seam (a designated syncer would need host support); the multiplication is
 * documented honestly rather than discovered in a rate-limit incident.
 *
 * The periodic timer's lifecycle is explicit: one arm per enable cycle (the
 * id is kept and repeat arming is refused), and {@see onDisable()} removes
 * it — an untracked repeating timer would survive disable and stack a new
 * full-walk timer on every enable/disable cycle. The enrichment drain
 * chain is ended by onDisable() too (backlog dropped, drain generation
 * bumped so an outstanding one-shot tick no-ops). The Workerman calls sit
 * behind the protected {@see addRepeatingTimer()} /
 * {@see removeRepeatingTimer()} / {@see addOneShotTimer()} seams (same
 * posture as HttpClient's sendOnce/tick seams) because the Workerman
 * runtime does not exist in a test process; that is why this class is not
 * `final`.
 *
 * @package Phlix\Plugins\Metadata\AniList
 */
class AniListPlugin implements LifecycleInterface, ConfigurableInterface
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

    /**
     * Page size for one watchlist fetch (AniList's own perPage maximum).
     */
    private const SYNC_PAGE_SIZE = 50;

    /**
     * Backstop on the watchlist page walk: 100 pages x 50 = 5000 entries per
     * status. Real AniList lists walk out well below this; the cap bounds the
     * request budget of one sync tick against a flood-prone or pathological
     * answer, and hitting it is LOUD (never a silent truncation).
     */
    private const MAX_SYNC_PAGES = 100;

    /**
     * Ceiling (characters) for one remote-derived value entering a log
     * context — the plugin-side twin of HttpClient's error-snippet cap, for
     * content (titles, mutation answers) rather than error bodies.
     */
    private const MAX_LOG_FIELD_CHARS = 200;

    private ?ItemRepository $itemRepository = null;

    /**
     * Never null after construction: the constructor falls back to a
     * NullLogger, so call sites take a plain arrow rather than nullsafe
     * noise. Reassignable (not readonly) — onEnable() may adopt the host
     * logger from the container when the caller injected none.
     */
    private LoggerInterface $logger;

    private ?AniListApi $api = null;

    /**
     * Bounded FIFO of media item IDs awaiting enrichment.
     *
     * @var list<string>
     */
    private array $enrichQueue = [];

    /** Whether a drain timer is currently armed (prevents double-arming). */
    private bool $drainArmed = false;

    /**
     * Drain-chain generation, bumped by {@see onDisable()}.
     *
     * The drain timer is a one-shot Workerman timer whose closure captures
     * the generation current at arming; a tick whose captured generation no
     * longer matches is a stale leftover from a disabled cycle and no-ops.
     * Without it, a tick already in flight at disable would rebuild the API
     * client (re-holding the bearer secret {@see onDisable()} just dropped)
     * and only miss the network because the repository happened to be null —
     * and after a re-enable it would run beside the new cycle's chain,
     * doubling the enrichment rate.
     */
    private int $drainGeneration = 0;

    /**
     * mal_id => AniList entry (null = definitive not-found), scoped to one
     * drain cycle (M-ANILIST-15).
     *
     * A burst of stop events can carry several items of the SAME series (same
     * mal_id), and each queued item re-hit getMediaByMalId() for it. Transient
     * failures are deliberately NOT memoized — a transport hiccup is not a
     * definitive absence — and the memo resets when the queue runs dry so a
     * later cycle re-resolves fresh.
     *
     * @var array<int, array<string, mixed>|null>
     */
    private array $lookupMemo = [];

    /**
     * Master on/off (the `enabled` setting). False disables EVERY AniList
     * path — event-driven enrichment and progress write-back as well as the
     * host-armed periodic sync (the gate is {@see isConfigured()}).
     */
    private bool $enabled = false;

    /**
     * Workerman timer id of the repeating periodic-sync timer, or null when
     * no timer is armed. Kept so {@see onDisable()} can remove the timer:
     * Workerman timers repeat until removed, and an untracked id strands a
     * live full-walk sync closure behind a disabled plugin.
     */
    private ?int $periodicTimerId = null;

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
     * A reconfigure swaps the settings object, so the lazily built API
     * client is dropped with it: that client signed every request with the
     * OLD bearer token, and on a resident worker a rotated `access_token`
     * would otherwise 401 forever with only a restart as the cure. The
     * next call rebuilds it through {@see ensureApi()} on the new token.
     *
     * @param array<string, mixed> $settings Key-value settings from plugins.settings_json
     *
     * @return void
     */
    public function configure(array $settings): void
    {
        $this->settings = AniListSettings::fromArray($settings);
        $this->enabled = ($settings['enabled'] ?? false) === true;
        $this->api = null;
    }

    /**
     * @param ContainerInterface $container Host PSR-11 container
     *
     * @return void
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
            $this->logger->warning('AniList: onEnable wiring failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // Arming a Workerman timer is non-blocking; the sync callback connects lazily.
        // NOTE: this whole method runs inside EVERY forked worker, so each
        // worker arms its own periodic sync timer below — the interval is
        // per-worker, not server-global (see schedulePeriodicSync()). The
        // master enable gates arming (schedulePeriodicSync() re-checks the
        // full isConfigured() gate): with enabled=false nothing here may
        // leave a timer that later dials AniList.
        if ($this->enabled && $this->settings->syncEnabled) {
            $this->schedulePeriodicSync();
        }
    }

    /**
     * Release resources on disable.
     *
     * Removes the repeating periodic-sync timer (an armed Workerman timer
     * fires until explicitly removed — leaving it would keep walking the
     * watchlist behind a disabled plugin) and drops the lazily built API
     * client, which holds the bearer token and the vendor TLS connection
     * pools. A later enable() re-arms and reconnects lazily.
     *
     * The enrichment drain chain is ended explicitly rather than by the
     * accident of a null repository: the backlog and the per-cycle lookup
     * memo are dropped, the drain-armed flag is cleared honestly (any
     * outstanding one-shot tick is now stale), and the drain generation is
     * bumped so that stale tick no-ops instead of rebuilding the client or
     * running beside a later cycle's chain.
     *
     * @return void
     */
    public function onDisable(): void
    {
        if ($this->periodicTimerId !== null) {
            $this->removeRepeatingTimer($this->periodicTimerId);
            $this->periodicTimerId = null;
        }

        $this->enrichQueue = [];
        $this->resetLookupMemo();
        $this->drainArmed = false;
        $this->drainGeneration++;

        $this->api = null;
        $this->itemRepository = null;
    }

    /**
     * Return the event subscriptions for this plugin.
     *
     * @return array<class-string, string|callable>
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
     */
    public function onMediaItemAdded(MediaItemAdded $event): void
    {
        if ($this->isConfigured() === false) {
            return;
        }

        if ($this->settings->autoMatch === false) {
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
            $this->logger->warning('AniList: enrichment queue full, dropping item', [
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
     * The closure captures the current {@see self::$drainGeneration}; a tick
     * that fires after {@see onDisable()} bumped it is stale and returns
     * without touching the queue, the flag or the API client.
     *
     * @return void
     */
    private function armDrainTimer(): void
    {
        if ($this->drainArmed) {
            return;
        }

        $generation = $this->drainGeneration;
        $armed = $this->addOneShotTimer(self::ENRICH_INTERVAL_SEC, function () use ($generation): void {
            if ($generation !== $this->drainGeneration) {
                return; // stale tick from a disabled cycle — never dial
            }

            $this->onDrainTick();
        });

        if ($armed === true) {
            $this->drainArmed = true;

            return;
        }

        // Timer unavailable (not inside a Workerman worker). Leave the items
        // queued rather than draining inline; events are only dispatched in
        // resident workers where the timer is available.
        $this->logger->debug('AniList: enrichment timer unavailable, deferring drain');
    }

    /**
     * Run one drain-timer tick: drain a single item, then re-arm while the
     * queue still has backlog.
     *
     * The tick is extracted from the timer callback (and the drain guarded)
     * because the timer is one-shot: if a throwing item escaped the callback,
     * the spent timer would never re-arm and every queued item behind it would
     * strand until some unrelated event happened to arm a new one. A faulting
     * item is therefore logged and dropped from the queue (drainOne() pops
     * before work), and the walk always continues (M-ANILIST-06).
     *
     * The full {@see isConfigured()} gate is re-checked at every tick (the
     * runScheduledSync() posture): a configure() that turns the master
     * enable off, or clears the token/username, while a tick is outstanding
     * ends the chain here — the backlog is dropped (event paths refuse new
     * items while unconfigured anyway) and nothing re-arms or dials.
     *
     * @return void
     */
    private function onDrainTick(): void
    {
        $this->drainArmed = false;

        if ($this->isConfigured() === false) {
            $this->enrichQueue = [];
            $this->resetLookupMemo();

            return;
        }

        try {
            $this->drainOne();
        } catch (\Throwable $e) {
            $this->logger->warning('AniList: enrichment drain tick failed', [
                'error' => $e->getMessage(),
                'queue_size' => count($this->enrichQueue),
            ]);
        }

        if ($this->enrichQueue === []) {
            $this->resetLookupMemo();

            return;
        }

        $this->armDrainTimer();
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
     */
    public function onPlaybackStopped(PlaybackStopped $event): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        if ($this->settings->syncEnabled === false) {
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
        if (is_int($anilistId) === false) {
            $this->logger->debug('AniList: no anilist_id on media item, skipping progress sync', [
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
            $this->logger->debug('AniList: no external ID found for media item', [
                'media_item_id' => $mediaItemId,
                'name' => $mediaItem->name,
            ]);
            return;
        }

        $anilistEntry = $this->lookupAniListEntry($externalId);
        if ($anilistEntry === null) {
            $this->logger->debug('AniList: no entry found for external ID', [
                'media_item_id' => $mediaItemId,
                'external_id' => $externalId,
            ]);
            return;
        }

        $metadata = $this->buildMetadataFromEntry($anilistEntry);
        $this->updateMediaItemMetadata($mediaItemId, $metadata);

        // Log the SCRUBBED stored projection, not the raw entry field:
        // AniList titles are upstream user-editable and the raw value can
        // carry bidi/control characters that spoof text wherever the log
        // renders (the storage copy already went through scrubRemoteText());
        // the log copy is additionally length-capped for display.
        $this->logger->info('AniList: enriched media item', [
            'media_item_id' => $mediaItemId,
            'anilist_id' => $metadata['anilist_id'],
            'title' => self::titleForLog(self::optionalString($metadata['title_romaji'])),
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
     * Look up an entry on AniList by its MyAnimeList ID.
     *
     * The body only ever resolves `mal_id` values via getMediaByMalId(); the
     * former `$type` parameter and the "or AniList ID" claim in the old docblock
     * were never honored and are gone.
     *
     * @param int $externalId MyAnimeList ID (from the item's `mal_id` metadata)
     *
     * @return array<string, mixed>|null The AniList entry or null
     */
    private function lookupAniListEntry(int $externalId): ?array
    {
        if ($this->api === null) {
            return null;
        }

        // Within one drain cycle an ID resolves once (M-ANILIST-15):
        // array_key_exists (not ??) so a memoized definitive-null hit counts.
        if (array_key_exists($externalId, $this->lookupMemo) === true) {
            return $this->lookupMemo[$externalId];
        }

        try {
            $entry = $this->api->getMediaByMalId($externalId);
            $this->lookupMemo[$externalId] = $entry;

            return $entry;
        } catch (AniListApiException $e) {
            $this->logger->warning('AniList: failed to look up entry by MAL ID', [
                'mal_id' => $externalId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Drop the per-drain-cycle mal_id lookup memo.
     *
     * Called by the drain timer once the queue runs dry, so the next enrichment
     * burst resolves fresh (M-ANILIST-15).
     *
     * @return void
     */
    private function resetLookupMemo(): void
    {
        $this->lookupMemo = [];
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
        $title = self::fragment($entry['title'] ?? null);
        $coverImage = self::fragment($entry['coverImage'] ?? null);

        return [
            'anilist_id' => is_int($entry['id'] ?? null) ? $entry['id'] : null,
            'mal_id' => is_int($entry['idMal'] ?? null) ? $entry['idMal'] : null,
            'title_romaji' => self::scrubRemoteText(self::optionalString($title['romaji'] ?? null)),
            'title_english' => self::scrubRemoteText(self::optionalString($title['english'] ?? null)),
            'title_native' => self::scrubRemoteText(self::optionalString($title['native'] ?? null)),
            'description' => self::scrubRemoteText(self::descriptionText($entry['description'] ?? null)),
            'average_score' => is_int($entry['averageScore'] ?? null) ? $entry['averageScore'] : null,
            'popularity' => is_int($entry['popularity'] ?? null) ? $entry['popularity'] : null,
            'favourites' => is_int($entry['favourites'] ?? null) ? $entry['favourites'] : null,
            'genres' => self::stringListOrEmpty($entry['genres'] ?? null),
            'format' => is_string($entry['format'] ?? null) ? $entry['format'] : null,
            'status' => is_string($entry['status'] ?? null) ? $entry['status'] : null,
            'episodes' => is_int($entry['episodes'] ?? null) ? $entry['episodes'] : null,
            'duration' => is_int($entry['duration'] ?? null) ? $entry['duration'] : null,
            'chapters' => is_int($entry['chapters'] ?? null) ? $entry['chapters'] : null,
            'volumes' => is_int($entry['volumes'] ?? null) ? $entry['volumes'] : null,
            'season' => is_string($entry['season'] ?? null) ? $entry['season'] : null,
            'season_year' => is_int($entry['seasonYear'] ?? null) ? $entry['seasonYear'] : null,
            'cover_image_large' => self::httpsUrlOrNull(self::optionalString($coverImage['large'] ?? null)),
            'cover_image_extralarge' => self::httpsUrlOrNull(self::optionalString($coverImage['extraLarge'] ?? null)),
            'banner_image' => self::httpsUrlOrNull(self::optionalString($entry['bannerImage'] ?? null)),
            'start_date' => $this->parseFuzzyDate($entry['startDate'] ?? null, 'startDate'),
            'end_date' => $this->parseFuzzyDate($entry['endDate'] ?? null, 'endDate'),
            'next_airing_episode' => $this->parseNextAiringEpisode($entry['nextAiringEpisode'] ?? null),
        ];
    }

    /**
     * Narrow a nested remote object fragment (`title`, `coverImage`) to an
     * array at the trust boundary.
     *
     * The entry's sub-objects arrive as mixed json_decode values; reading
     * `['romaji']` / `['large']` straight off them was offset access on
     * mixed (five baseline suppressions). Same posture as
     * AniListApi::readMedia(): NARROW instead of annotate. A missing, null
     * or non-array fragment answers as the empty array (a list passes
     * through; its named-member reads miss), so every member read below
     * falls through to null exactly as an absent field did.
     *
     * @param mixed $value Raw fragment decoded from the AniList response
     *
     * @return array<array-key, mixed>
     */
    private static function fragment(mixed $value): array
    {
        return is_array($value) === true ? $value : [];
    }

    /**
     * Narrow a raw remote value to string-or-null at the trust boundary.
     *
     * @param mixed $value Raw value decoded from the AniList response
     *
     * @return string|null
     */
    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * Reduce a remote (asHtml: false) description to plain text.
     *
     * Markup is stripped, the result is truncated to 2000 characters, and a
     * value that cannot be decoded is dropped rather than stored raw.
     *
     * @param mixed $value Raw remote `description` value
     *
     * @return string|null
     */
    private static function descriptionText(mixed $value): ?string
    {
        if (is_string($value) === false) {
            return null;
        }

        $plain = preg_replace('/<[^>]+>/', '', $value);

        return $plain === null ? null : mb_substr($plain, 0, 2000);
    }

    /**
     * Scrub C0/C1 and bidi control characters out of a remote display string.
     *
     * AniList titles and descriptions are upstream user-editable content;
     * invisible control or right-to-left override characters are payload that
     * can spoof text wherever the stored value renders. Tab/LF/CR survive;
     * undecodable input is dropped rather than stored raw.
     *
     * @param string|null $value Already-narrowed string candidate
     *
     * @return string|null
     */
    private static function scrubRemoteText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $scrubbed = preg_replace(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{0080}-\x{009F}'
            . '\x{200E}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u',
            '',
            $value
        );

        return is_string($scrubbed) ? $scrubbed : null;
    }

    /**
     * Project a scrubbed remote title for one log line.
     *
     * Input is the value the storage copy already scrubbed (control/bidi
     * families removed); this only adds the display cap and the missing
     * placeholder the old raw-field fallback provided.
     */
    private static function titleForLog(?string $scrubbedTitle): string
    {
        if ($scrubbedTitle === null || $scrubbedTitle === '') {
            return 'unknown';
        }

        return self::boundedForLog($scrubbedTitle);
    }

    /**
     * Project the admin-supplied AniList username for one log line.
     *
     * {@see AniListSettings::fromArray()} already refuses a dirty username
     * at the parse boundary; this is the log-site half for consumers that
     * construct settings directly. Control/bidi families go through
     * {@see self::scrubRemoteText()}, the tab/LF/CR that helper keeps for
     * stored prose are dropped too (a line break in a log value forges a
     * line), and the result is capped by {@see self::boundedForLog()}.
     */
    private static function usernameForLog(string $username): string
    {
        $scrubbed = str_replace(["\r", "\n", "\t"], '', self::scrubRemoteText($username) ?? '');

        return self::boundedForLog($scrubbed);
    }

    /**
     * Render a remote answer (mutation result, decoded payload fragment) for
     * a log line as a bounded string.
     *
     * json_encode escapes C0 control characters, so the encoded form is
     * log-safe on the control-char axis; the cap bounds the flood axis. An
     * unserializable value is named, never imploded into empty prose.
     *
     * @param array<array-key, mixed> $value Remote-derived array
     */
    private static function remoteJsonForLog(array $value): string
    {
        $encoded = json_encode($value);

        return self::boundedForLog(is_string($encoded) === true ? $encoded : '(unserializable)');
    }

    /**
     * Cut a printable projection to the log ceiling, marking the cut.
     */
    private static function boundedForLog(string $text): string
    {
        return mb_strlen($text) > self::MAX_LOG_FIELD_CHARS
            ? mb_substr($text, 0, self::MAX_LOG_FIELD_CHARS) . '[truncated]'
            : $text;
    }

    /**
     * Element-wise parse of a remote string list (AniList `genres`).
     *
     * JSON gives no guarantees about list element types; non-strings are
     * dropped so a malformed payload cannot inject typed garbage into
     * metadata_json.
     *
     * @param mixed $value Raw remote array-or-nothing value
     *
     * @return list<string>
     */
    private static function stringListOrEmpty(mixed $value): array
    {
        if (is_array($value) === false) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * Accept remote image URLs over `https:` only; anything else is nulled.
     *
     * Covers and banners are rendered by the SPA and fetched by clients; an
     * `http:`, `javascript:` or unknown-scheme value from the API is not a
     * URL this plugin will vouch for.
     *
     * @param string|null $value Already-narrowed string candidate
     *
     * @return string|null
     */
    private static function httpsUrlOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return parse_url($value, PHP_URL_SCHEME) === 'https' ? $value : null;
    }

    /**
     * Parse an AniList FuzzyDate into a trusted shape before it is stored.
     *
     * Accepted: an array whose `year`/`month`/`day` sub-keys are each int or
     * null (AniList sends partial dates with null components). A string, a
     * non-array blob, or non-int components are contract violations: the field
     * is logged away and stored as null, never raw.
     *
     * @param mixed $value Raw remote `startDate`/`endDate` value
     * @param string $field Field name for the debug log
     *
     * @return array{year: int|null, month: int|null, day: int|null}|null
     */
    private function parseFuzzyDate(mixed $value, string $field): ?array
    {
        if (is_array($value) === true) {
            $year = $value['year'] ?? null;
            $month = $value['month'] ?? null;
            $day = $value['day'] ?? null;

            $componentsValid = (is_int($year) || $year === null)
                && (is_int($month) || $month === null)
                && (is_int($day) || $day === null);

            if ($componentsValid === true && ($year !== null || $month !== null || $day !== null)) {
                return ['year' => $year, 'month' => $month, 'day' => $day];
            }
        }

        if ($value !== null) {
            $this->logger->debug('AniList: rejecting malformed date field', ['field' => $field]);
        }

        return null;
    }

    /**
     * Parse the selected `nextAiringEpisode` fields into a trusted shape.
     *
     * The queries fetch exactly `{ episode airingAt }`; anything else arriving
     * under that key is a contract violation and is dropped, not stored.
     *
     * @param mixed $value Raw remote `nextAiringEpisode` value
     *
     * @return array{episode: int, airingAt: int}|null
     */
    private function parseNextAiringEpisode(mixed $value): ?array
    {
        if (is_array($value) === true) {
            $episode = $value['episode'] ?? null;
            $airingAt = $value['airingAt'] ?? null;

            if (is_int($episode) === true && is_int($airingAt) === true) {
                return ['episode' => $episode, 'airingAt' => $airingAt];
            }
        }

        if ($value !== null) {
            $this->logger->debug('AniList: rejecting malformed nextAiringEpisode payload');
        }

        return null;
    }

    /**
     * Update a media item's metadata in the repository.
     *
     * @param string $mediaItemId Media item UUID
     * @param array<string, mixed> $metadata New metadata to merge
     * @param bool $incomingWins When true the incoming values are authoritative
     *        and overwrite existing keys (watchlist ticks for their own
     *        namespace); when false the merge is gap-fill, never clobbering a
     *        value written by a higher-priority source (enrichment default).
     *        Empty incoming values never clear in either mode.
     *
     * @return void
     */
    private function updateMediaItemMetadata(string $mediaItemId, array $metadata, bool $incomingWins = false): void
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

            // Merge under the caller's authority mode (gap-fill for enrichment,
            // overwrite for the watchlist's own fields); foreign namespaces
            // written by higher-priority sources (TMDB/IMDb) survive either way.
            $mergedMetadata = $this->gapFillMerge($existingMetadata, $metadata, $incomingWins);
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
            $this->logger->error('AniList: failed to persist media item metadata', [
                'media_item_id' => $mediaItemId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Merge new metadata into existing metadata.
     *
     * Gap-fill mode (default): never clobbers an existing non-empty value.
     * Authoritative mode (`$incomingWins`): the caller's keys overwrite what is
     * stored — used by watchlist ticks, whose AniList fields are the source of
     * truth for their own namespace. Either way an EMPTY incoming value never
     * contributes and never clears, and keys the merge does not carry survive.
     *
     * @param array<string, mixed> $existing Current metadata blob
     * @param array<string, mixed> $incoming Candidate values from AniList
     * @param bool $incomingWins Overwrite existing keys with incoming values
     *
     * @return array<string, mixed> Merged metadata
     */
    private function gapFillMerge(array $existing, array $incoming, bool $incomingWins = false): array
    {
        $merged = $existing;

        foreach ($incoming as $key => $value) {
            if ($this->isEmptyValue($value)) {
                continue; // nothing to contribute
            }
            if (
                $incomingWins === false
                && array_key_exists($key, $existing)
                && !$this->isEmptyValue($existing[$key])
            ) {
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
     * Monotonic guard (M-ANILIST-15): SaveMediaListEntry overwrites `progress`
     * blindly, so an older episode reaching this path (re-watched episode,
     * out-of-order stop event, replay) would walk the user's saved progress
     * BACKWARDS. The viewer's current progress is probed first and the write is
     * skipped unless it is strictly newer. A failed probe must never block the
     * write: on uncertainty the previous always-write behavior stands.
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
            $savedProgress = $this->api->getMediaListProgress($anilistId);
        } catch (AniListApiException $e) {
            $savedProgress = null;
            $this->logger->debug('AniList: progress probe failed; writing without the monotonic check', [
                'anilist_id' => $anilistId,
                'error' => $e->getMessage(),
            ]);
        }

        if ($savedProgress !== null && $episodeNumber <= $savedProgress) {
            $this->logger->info('AniList: local progress is not newer than saved; skipping write', [
                'anilist_id' => $anilistId,
                'episode' => $episodeNumber,
                'saved_progress' => $savedProgress,
            ]);

            return;
        }

        try {
            $result = $this->api->saveEpisodeProgress($anilistId, $episodeNumber);

            // The SaveMediaListEntry answer is remote-controlled content:
            // log a bounded JSON projection, never the decoded array whole
            // (json_encode escapes control characters, and the cap keeps a
            // pathological payload from flooding the log stream).
            $this->logger->info('AniList: synced episode progress', [
                'anilist_id' => $anilistId,
                'episode' => $episodeNumber,
                'result' => self::remoteJsonForLog($result),
            ]);
        } catch (AniListApiException $e) {
            $this->logger->warning('AniList: failed to sync episode progress', [
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

        $accessToken = $this->settings->accessToken() ?? '';
        if ($accessToken === '') {
            return;
        }

        $this->api = new AniListApi(
            new HttpClient($this->logger),
            $accessToken,
            $this->logger
        );
    }

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

    /**
     * Arm the periodic watchlist sync timer.
     *
     * Takes no arguments: collaborators were moved to {@see onEnable()} long
     * ago and the timer interval is resolved by {@see syncIntervalSeconds()}.
     *
     * Gate law: the full {@see isConfigured()} gate — master enable AND token
     * AND username — refuses arming, matching the event paths; a disabled
     * plugin must never leave a live timer that dials AniList. Arming is
     * idempotent while a timer is outstanding (same discipline as the
     * drainArmed bool), so repeated enable() calls cannot stack timers.
     *
     * HONEST SCOPE: one repeating timer per worker process. The host forks a
     * pool of resident Workerman workers and onEnable() executes in every
     * one of them, so the aggregate sync RATE is the worker count times the
     * configured interval's rate, and each tick performs its own full page
     * walk. No seam available to this plugin elects a single designated
     * syncer (a host-side lock or scheduler would be required); the
     * multiplication stands as documented behavior.
     */
    private function schedulePeriodicSync(): void
    {
        if (!$this->isConfigured() || $this->settings->syncEnabled === false) {
            return;
        }

        if ($this->periodicTimerId !== null) {
            return; // already armed this enable cycle — never stack
        }

        $this->periodicTimerId = $this->addRepeatingTimer(
            $this->syncIntervalSeconds(),
            function (): void {
                $this->runScheduledSync();
            }
        );
    }

    /**
     * Arm one repeating Workerman timer.
     *
     * Protected seam (HttpClient sendOnce/tick posture): the Workerman
     * runtime exists only inside a host worker, so the raw call is pinned
     * here and the lifecycle laws are testable through an override. Returns
     * the timer id for {@see removeRepeatingTimer()}, or null when the arm
     * failed — outside a Workerman process the class is absent and the
     * Error is caught, leaving the plugin simply unarmed (nothing throws
     * into boot).
     */
    protected function addRepeatingTimer(int $intervalSeconds, callable $callback): ?int
    {
        try {
            return \Workerman\Timer::add($intervalSeconds, $callback);
        } catch (\Throwable) {
            // Timer not available outside Workerman process
            return null;
        }
    }

    /**
     * Arm one non-repeating Workerman timer (the enrichment drain tick).
     *
     * Protected seam, same posture as {@see addRepeatingTimer()}: the raw
     * Workerman call is pinned here so the drain-chain lifecycle laws are
     * testable through an override. Returns false when the arm failed —
     * outside a Workerman process the class is absent and the Error is
     * caught.
     */
    protected function addOneShotTimer(int $delaySeconds, callable $callback): bool
    {
        try {
            \Workerman\Timer::add($delaySeconds, $callback, [], false);

            return true;
        } catch (\Throwable) {
            // Timer not available outside Workerman process
            return false;
        }
    }

    /**
     * Disarm a timer armed through {@see addRepeatingTimer()}.
     *
     * The paired seam for onDisable(); failing to remove (no Workerman
     * runtime, or an id the loop never knew) cannot break disable, so the
     * error is swallowed the same way the arm swallows it.
     */
    protected function removeRepeatingTimer(int $timerId): void
    {
        try {
            \Workerman\Timer::remove($timerId);
        } catch (\Throwable) {
            // Nothing to disarm outside a Workerman process
        }
    }

    /**
     * Execute the periodic watchlist sync.
     *
     * The master enable is re-checked at every tick (not just at arming):
     * a settings reconfigure can disable the plugin while its timer is
     * already outstanding on a resident worker.
     *
     * @return void
     */
    private function runScheduledSync(): void
    {
        if (!$this->isConfigured() || $this->settings->syncEnabled === false) {
            return;
        }

        $this->ensureApi();

        try {
            $this->syncWatchlistFromAniList();
        } catch (\Throwable $e) {
            $this->logger->warning('AniList periodic sync failed', [
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

        $logUsername = self::usernameForLog($username);

        $this->logger->info('AniList: starting watchlist sync', [
            'username' => $logUsername,
        ]);

        $statuses = ['CURRENT', 'COMPLETED', 'PLANNING', 'PAUSED', 'DROPPED'];
        foreach ($statuses as $status) {
            $this->syncStatusList($username, $status);
        }

        $this->logger->info('AniList: watchlist sync completed', [
            'username' => $logUsername,
        ]);
    }

    /**
     * Sync a specific status list from AniList.
     *
     * The list is walked page by page ({@see self::SYNC_PAGE_SIZE} per fetch,
     * stable MEDIA_ID order server-side) until a page comes back short — a
     * single page-1 fetch silently truncated every watchlist beyond 50
     * entries per status. The walk is capped at {@see self::MAX_SYNC_PAGES}
     * pages and hitting the cap warns loudly.
     *
     * @param string $username AniList username
     * @param string $status Status to sync
     *
     * @return void
     */
    private function syncStatusList(string $username, string $status): void
    {
        $api = $this->api;
        if ($api === null) {
            return;
        }

        for ($page = 1; $page <= self::MAX_SYNC_PAGES; $page++) {
            try {
                $entries = $api->getUserAnimeList($username, $status, $page, self::SYNC_PAGE_SIZE);
            } catch (AniListApiException $e) {
                $this->logger->warning('AniList: failed to sync status list', [
                    'username' => self::usernameForLog($username),
                    'status' => $status,
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);

                return;
            }

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

            if (count($entries) < self::SYNC_PAGE_SIZE) {
                return; // short (or empty) page = end of this status's list
            }
        }

        $this->logger->warning('AniList: status list hit the pagination cap; remainder not synced', [
            'username' => self::usernameForLog($username),
            'status' => $status,
            'pages' => self::MAX_SYNC_PAGES,
        ]);
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

        // AniList's MediaList.score is a Float — users score in halves (8.5).
        // An is_int()-only boundary silently nulled every half-point score,
        // so the authoritative watchlist write carried no score at all.
        // Accept the decoded numeric (int|float); anything else is not a
        // score and contributes nothing (the merge never stores empty).
        $rawScore = $entry['score'] ?? null;

        $metadata = [
            'anilist_id' => $anilistId,
            'anilist_status' => $status,
            'anilist_score' => (is_int($rawScore) || is_float($rawScore)) === true ? $rawScore : null,
            'anilist_progress' => is_int($entry['progress'] ?? null) ? $entry['progress'] : null,
            // The AniList favourite flag is deliberately absent: this path never
            // fetches it, and hardcoding it false wrote a lie into metadata_json
            // (it is also excluded from the gap-fill merge, so the false could
            // never be corrected by a later enrichment pass) — M-ANILIST-14.
        ];

        // Map the entry back to a local row through the host metadata lookup
        // (release-matrix notes in resolveMediaItemIdByMalId()).
        $mediaItemId = $this->resolveMediaItemIdByMalId($malId);
        if ($mediaItemId === null) {
            $this->logger->debug(
                'AniList: no local item carries this MAL id (or the host lacks the '
                . 'metadata lookup hook); watchlist status skipped',
                ['mal_id' => $malId]
            );
            return;
        }

        // Watchlist fields are authoritative for the AniList namespace, so they
        // OVERWRITE previously stored anilist_* values — the gap-fill mode used
        // by enrichment could never move a stored status (M-ANILIST-01).
        $this->updateMediaItemMetadata($mediaItemId, $metadata, true);
    }

    /**
     * Resolve a local media item ID from a MyAnimeList ID stored in the item's
     * metadata blob.
     *
     * Uses the host {@see ItemRepository::findByMetadataField()}, which exists
     * on host master (phlix-server bfd4af98) but NOT in the released v1.2.x
     * tags — hence the runtime probe: calling it blind would fatal every
     * watchlist tick on a current release host. An old host degrades to the
     * documented skip (logged, never silent); a modern host resolves exactly
     * one row whose `metadata_json.mal_id` matches, or null for a genuine miss.
     *
     * @param int $malId MyAnimeList ID
     *
     * @return string|null Resolved media item UUID, or null when unresolvable
     */
    private function resolveMediaItemIdByMalId(int $malId): ?string
    {
        if ($this->itemRepository === null) {
            return null;
        }

        if (method_exists($this->itemRepository, 'findByMetadataField') === false) {
            $this->logger->debug(
                'AniList: host predates the metadata lookup hook; watchlist status skipped',
                ['mal_id' => $malId]
            );

            return null;
        }

        $row = $this->itemRepository->findByMetadataField('mal_id', $malId);
        if ($row === null) {
            return null;
        }

        $id = $row['id'] ?? null;

        return is_string($id) === true && $id !== '' ? $id : null;
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
     */
    public function getSettings(): AniListSettings
    {
        return $this->settings;
    }

    /**
     * Get a REDACTED settings projection safe to return to the admin SPA.
     *
     * @return array<string, mixed>
     */
    public function getSettingsForSpa(): array
    {
        return $this->settings->toSpaArray();
    }
}
