# Changelog

All notable changes to `phlix-plugin-anilist` are documented here. Dates are
commit dates; SHAs point at the introducing commits on `master`.

## Semver-era disclosure

`plugin.json` currently declares version **0.4.0**, but **no 0.3.x or 0.4.x
release has ever been tagged**: the published git tags stop at `v0.2.0`
(2026-07-10, `64e61a9`; before that `v0.1.0`, 2026-07-09, `766a834`). The
phlix-plugins catalog pins release entries by tag with the version read from
`plugin.json` at that tag, so 0.3.0 (introduced untagged by `7f8bbf7`,
2026-07-20) and 0.4.0 (introduced untagged by `1ddf29a`, 2026-07-22) are only
ever resolvable through the dev channel (default-branch HEAD pin, version
`"dev"`, verified:false) until the owner cuts matching tags.

## [Unreleased] — 0.4.x development line

### Fixed
- ANL-3 — AniListApi re-checks the bearer token at construction via shared AniListSettings::isWellFormedToken() (throws InvalidArgumentException on CR/LF/space/control/non-ASCII); TOKEN_PATTERN now \z-anchored so a trailing LF is refused at direct construction (fromArray() still trims) (f562d55).
- ANL-4 — dispatch.yml passes github.ref/sha/repository to the gh api call via env: (REF/SHA/REPO, quoted) instead of inlining ${{ }} into the run script (f6b5404).
- ANL-5 — the admin AniList username is guarded at the AniListSettings::fromArray() parse boundary (trim, then 1-64 printable ASCII via USERNAME_PATTERN, mirroring parseToken(); a dirty value fails closed to "" = unconfigured), and every sync log line carrying it (start/completed/status-list failure/pagination cap) projects it through usernameForLog() = scrubRemoteText() + CR/LF/TAB drop + boundedForLog(), so a direct-construction consumer cannot forge or flood a log line either.
- ANL-6 — HttpClient::safeErrorBodySnippet() byte-caps a remote error body at MAX_ERROR_BODY_SCAN_BYTES (500 chars x 4 UTF-8 bytes + 16 = 2016) BEFORE the control-char scrub, via mb_strcut() so the cut never splits a multibyte sequence; the >= 400 throw (which precedes the 8 MiB success-path ceiling, unchanged) no longer regex-walks a hostile body of any size, a cut body is always `[truncated]` marked, and 4xx/5xx envelopes are byte-identical for clean bodies (tests/Unit/AniListErrorBodyCapTest.php).
- ANL-7 — AniListApi::saveEpisodeProgress()/toggleFavorite()/updateStatus() narrow their mutation payload through the private readMutationPayload() boundary reader (readMedia() posture) instead of a decorative `@var array<string, mixed>` over the mixed `$data[<field>] ?? []` read: a non-array fragment under the key answers as [] rather than escaping as a TypeError, and only string-keyed members survive so the declared array<string, mixed> return is earned; well-formed payloads are unchanged and no baseline entry is added (tests/Unit/AniListMutationNarrowingTest.php).
- ANL-8 — AniListPlugin::buildMetadataFromEntry() narrows the `title` and `coverImage` sub-objects through the private fragment() boundary reader (is_array, else []) instead of reading offsets off the mixed json_decode values; a null/scalar/list fragment answers as absent fields, well-formed entries project unchanged, and the five "Cannot access offset ... on mixed" phpstan.baseline.neon suppressions are retired in the same commit (tests/Unit/AniListFragmentNarrowingTest.php).
- Review-campaign batch W14-L8 (estate lane `w14L8`, register anchors
  `plugin-anilist__srphp.md` #6/#7/#16, `plugin-anilist__secaudit.md`
  #1/#4/#5/#7; items PG-165…PG-170):
  - **PG-165 — not-found is a typed signal, never message prose.**
    `AniListApiException` now carries the answering HTTP status and an
    explicit not-found flag (`src/AniListApiException.php`), the transport
    attaches the status on every 4xx throw
    (`src/HttpClient.php request()`), and the GraphQL-envelope arm sets the
    flag only when a message EQUALS the documented `Not found` answer
    (`AniListApi::graphQLErrorsReportNotFound()`). The lookup wrappers
    classify through `$e->isNotFound()`
    (`src/AniListApi.php:getMediaById()/getMediaByMalId()`); the retired
    prose scan (`isNotFoundFailure()`) matched `not found` case-insensitively
    across messages that embed remote-controlled error text, so a
    revoked-token notice or CDN miss blurb containing the words was memoized
    as definitive absence and enrichment went silently quiet. A transport
    error answered with no HTTP status can never classify as not-found, and
    the ceiling refusal below deliberately carries no status.
  - **PG-166 — the four media queries share one field selection.** The
    ~26-field `Media` projection was copied four times and had already
    drifted (`searchManga` lacked `season`/`seasonYear`/`nextAiringEpisode`
    its twins carried); it is now `AniListApi::MEDIA_FIELDS`, interpolated by
    `searchAnime`/`searchManga`/`getMediaById`/`getMediaByMalId`
    (equivalence pinned in `tests/Unit/AniListW14L8Test.php`). Fields an
    entry type cannot answer return null — the same shape the mismatched
    copies already produced. The list queries' type-appropriate media
    sub-projections stay separate by design.
  - **PG-167 — typed boundary readers replace decorative `@var`s.** The four
    Page-list returns and two `Media` object returns carried `@var` shape
    promises over unchecked `json_decode` offset reads (with matching phpstan
    baseline suppressions). `readPageList()`/`readMedia()` narrow with real
    `is_array` guards (`src/AniListApi.php`), the cooperative-wait `$state`
    literal in `src/HttpClient.php` declares its shape and narrows
    `Retry-After` at the seam, and the decorative `(string)` cast at the
    transport-error throw is gone. Five `AniListApi.php` entries retired from
    `phpstan.baseline.neon`; the remaining plugin-side fragment entries are
    untouched.
  - **PG-168 — GraphQL error prose enters the log channel scrubbed and
    capped.** The HTTP-200 errors envelope used to compose raw remote prose
    into the exception message every catch site logs: newlines forged log
    entries, ANSI/bidi characters poisoned terminal viewers, size was
    unbounded. Each message now passes the transport's scrub-and-cap (now
    shared: `HttpClient::safeErrorBodySnippet()`), and the envelope carries
    at most `MAX_GRAPHQL_ERROR_MESSAGES` (5) messages with the overflow named
    as `(+N more errors)` (`src/AniListApi.php formatGraphQLErrors()`).
  - **PG-169 — the bearer token is guarded at the settings boundary.** A
    bearer value rides the Authorization header verbatim, so an embedded
    CR/LF or space is a header-splitting primitive. `AniListSettings` now
    enforces a printable-ASCII pattern (`TOKEN_PATTERN`): the constructor
    refuses a dirty token loudly, and the `fromArray()` parse boundary trims
    paste whitespace then fails CLOSED to "no token" (the plugin reports
    itself unconfigured instead of crash-looping a boot on a bad persisted
    blob) instead of ever replaying the value (`src/AniListSettings.php`).
  - **PG-170 — success-path response bodies have a ceiling.** The 4xx/5xx
    path worried about flood-capable payloads; the success path decoded
    anything. `HttpClient::MAX_RESPONSE_BYTES` (8 MiB) now refuses an
    oversized 200 body before `json_decode`, as an API failure that can
    never classify as absence (`src/HttpClient.php request()`).
  - Folded review rows: the decorative arm-path pin
    `test_periodic_timer_never_arms_while_disabled` was rebuilt through the
    timer spy (a gate-strip now reddens it — proven by mutation; the plain
    plugin form could not fail because the real `addRepeatingTimer()` seam
    catch-nulls in a test process). The W06-L5 lane digest attributed its
    suite delta as "+2A seam-pin rotation" where the split is +1A rotation
    and +1A mechanical file count of the namespace-parity sweep — the
    committed entry's own wording ("One disclosed golden move") was accurate,
    so nothing is rewritten; this batch's figures are simply taken from the
    measured red.
- Review-campaign batch W06-L5 (estate lane `w06L5`, register anchors
  `plugin-anilist__srphp.md` #1–#5, `plugin-anilist__secaudit.md` #2,
  `plugin-anilist__copywriter.md` #11; items PG-160…PG-164, PG-055):
  - **PG-160 — the master enable now gates every timer path.** The
    periodic-sync arm and tick consulted only the settings pair
    (`AniListSettings::isConfigured()` — token + username), so with
    `enabled=false` a resident worker whose timer predated the flip kept
    walking the watchlist forever. `onEnable()` now arms only behind the
    toggle (`src/AniListPlugin.php:228`), the arming site moved onto the
    plugin-level `isConfigured()` — enabled AND token AND username
    (`:1051`, `:977`) — and the tick re-checks on every fire (`:1115`),
    so a reconfigure that flips `enabled` off lands no later than the
    next tick. Forward-corrects this file's W05-L5 entry ("the host-armed
    periodic sync checks only token+username+`sync_enabled` (:943/:963)" —
    true when written, cured here) and flips the README gate sentence that
    carried the same split (`README.md:29-32`).
  - **PG-161 — reconfigure and disable drop the cached API client.**
    `configure()` swapped the settings object but left the lazily built
    `AniListApi` in place — that client signs every request with the OLD
    bearer token, so a rotated `access_token` on a resident worker 401'd
    until restart. Both paths now release it (`src/AniListPlugin.php:192`,
    `:251`); `ensureApi()` rebuilds lazily on next use, and its
    early-return-while-set law is untouched.
  - **PG-162 — the periodic timer is tracked and removable.** The id from
    `Timer::add()` was discarded, so `onDisable()` could not remove the
    repeating timer and an enable/disable cycle stacked another
    full-walk timer on the event loop. The id lives in `$periodicTimerId`
    (`src/AniListPlugin.php:154`), arming is refused while a timer is
    outstanding (the drainArmed discipline, `:1055`), and disable removes
    and clears it (`:246-249`). The raw Workerman calls moved behind new
    protected seams `addRepeatingTimer()`/`removeRepeatingTimer()`
    (`:1078`, `:1095`) — the HttpClient sendOnce/tick posture, since no
    Workerman runtime exists in a test process. Disclosed design change:
    `AniListPlugin` is no longer `final` (nothing pinned the finality; the
    lifecycle laws are now pinned behaviorally through the seams rather
    than through reflection into the vendor class).
  - **PG-163 — half-point scores reach the database.** AniList's
    `MediaList.score` is a Float (users score in halves); the watchlist
    projection ran it through an `is_int()`-only boundary, so every real
    score of 8.5 stored as null — the authoritative write carried no
    score at all. The boundary accepts the numeric types the JSON decoder
    produces, nothing looser (`src/AniListPlugin.php:1243-1248`);
    non-numerics still contribute nothing (the gap-fill merge never
    stores empties).
  - **PG-164 — remote-derived log fields are scrubbed and bounded.** The
    enrichment info line logged the RAW `title.romaji` (AniList titles are
    user-editable upstream — bidi/control characters spoof text wherever
    logs render) and the progress-sync info line attached the whole
    decoded mutation answer unbounded. Enrichment now logs the scrubbed
    stored projection (`src/AniListPlugin.php:481-485`), the answer
    becomes a bounded JSON projection via `remoteJsonForLog()` (`:961`),
    and the shared display ceiling `MAX_LOG_FIELD_CHARS` = 200 bounds
    both (`:103`, helpers `:664-697`). The same channel got the same
    treatment in `HttpClient`: vendor transport error text now flows
    through `safeErrorBodySnippet()` exactly like 4xx/5xx bodies already
    did (`src/HttpClient.php:306`), because every caller logs the
    exception message verbatim.
  - **PG-055 (info) — tagline truth.** README and `composer.json` lead
    with the two things the plugin actually does — "anime/manga metadata
    and watchlist sync, over the AniList GraphQL API" (`README.md:6-7`,
    `composer.json:4`) — replacing "GraphQL integration", which named the
    transport instead of the work.

  New regression surface: `tests/Unit/AniListW06L5Test.php` (19 cases —
  dial-while-disabled refusal, client invalidation and rebuild,
  arm-once/remove-on-disable/re-arm through the seams, the score boundary
  dataset, log scrub/cap probes, the transport-cap pin; 15 of the 19 red
  or fatal at the parent commit first — the four exceptions are deliberate
  positive controls: sync still walking when enabled, and the int/string/
  null score datasets). One disclosed golden move:
  `tests/Unit/AniListSyncIntervalTest.php`'s source-scan pin follows the
  call into the new seam — it still pins the variable-interval
  `Timer::add($intervalSeconds` and still refuses the hardcoded constant,
  and the interval law now also has behavioral proof through the seams.
  The phpstan-only `tests/Stub/WorkermanTimer.php` mirror gained a
  `remove()` signature beside `add()`, and the phpstan baseline's
  `Cannot access offset 'romaji' on mixed` suppression retired from
  count 2 to count 1 — the second raw fragment read was the very log
  site this batch scrubbed (the surviving one is the narrowed
  `buildMetadataFromEntry` boundary).

- Review-campaign copy batch W05-L5 (estate lane `w05L5`, register
  `plugin-anilist__copywriter.md`; items PG-049…PG-054) — README/`plugin.json`
  copy truth, code untouched:
  - the bare `bfd4af98` hash in the README now names its repository
    (pre-`phlix-server bfd4af98`), matching the in-product phrasing at
    `resolveMediaItemIdByMalId()` — the hash lives in phlix-server history and
    an admin reading this repo alone could not resolve it (PG-049);
  - "using external IDs" (both `auto_match` surfaces) overstated the key set:
    `findExternalId()` reads only the `mal_id` metadata key
    (`src/AniListPlugin.php:440-447`), so both now say "the item's
    MyAnimeList id (mal_id)" — the same honesty the code docblock gained in
    the minors wave (PG-050);
  - "Sync watched/progress" slash-jargon expanded to "Sync your watched
    status and episode progress" on the `sync_enabled` settings surfaces, and
    the README episode-progress bullet gained its missing determiner
    ("for each series") (PG-051);
  - the invented term "configured-gate" (and README's rival label "one shared
    gate") replaced everywhere with one plain formulation: every AniList
    feature requires the token AND the username
    (`AniListSettings::isConfigured()` at `src/AniListSettings.php:147-150`,
    enforced at every gate site). The Overview sentence also stopped claiming
    the master toggle gates EVERY feature — it gates the event-driven paths
    (`AniListPlugin.php:232/:354` via `isConfigured()` at :875-877), while the
    host-armed periodic sync checks only token+username+`sync_enabled`
    (:943/:963) — and the README settings table now carries the sibling
    (trakt) column set: Setting|Type|Required|Secret|Tier|Default|Description,
    secretness in its own column instead of "string (secret)" (PG-052, PG-054);
  - the `access_token` tooltip trimmed to the paste instruction plus the
    linked developer-settings page; the joint token-and-username gate is now
    stated once, under `username`, instead of duplicated verbatim-in-substance
    on both rows (PG-053).

- Review-campaign minors/info batch W03-L10 (estate lane `w03L10`, register
  `plugin-anilist__srphp.md`; items PG-037…PG-042):
  - the periodic sync timer is documented honestly as PER-WORKER: the host
    forks ~14 resident workers and `onEnable()` arms the timer in every one,
    so the aggregate sync rate is the worker count times the configured
    interval — N full page walks per tick against AniList's ~90 req/min
    budget. The class header, `schedulePeriodicSync()`, the `onEnable()` arm
    site and the README `sync_interval_minutes` row now all say so; electing
    a single designated syncer would need a host-side coordination seam this
    plugin does not have (PG-037);
  - `formatGraphQLErrors()` no longer lets `json_encode()`'s `false` implode
    an unserializable remote message (e.g. INF from a `1e999` literal) into
    EMPTY prose while `@return string` promised text; the segment now reads
    `(unserializable)`, pinned by a new regression test (PG-038);
  - `getUserMangaList()` carries `sort: [MEDIA_ID]` exactly like its anime
    twin: M-ANILIST-05 proved page-stitching is unsound without a fixed
    order, and the public manga twin handed any paging host consumer the
    same duplicate/drop hazard; pinned by a query-shape test (PG-039);
  - `AniListSettings::toArray()`'s docblock stops writing as if an in-repo
    path performed the persistence round-trip: the `$includeSecret: true`
    shape exists solely for the HOST settings-persistence contract — no
    caller inside this plugin passes it, and the tests exercise the branch
    directly (PG-040);
  - `AniListPlugin::$logger` is declared non-nullable — the constructor
    already guarantees a `NullLogger` fallback, so 23 nullsafe call sites
    collapse to plain arrows and the dead `?? new NullLogger()` in
    `ensureApi()` is gone; the property stays reassignable (not `readonly`)
    because `onEnable()` may still adopt the host logger (PG-041);
  - `tests/bootstrap.php` drops the dead `PlaybackStopped` stub branch: the
    class ships in `vendor/detain/phlix-shared` (`class_exists()` true at
    runtime), so the alias never fired and the tests already build the real
    6-argument event. The genuinely host-only `MediaItem` branch stays and
    now documents that boundary (a stub for a shipped class is misleading
    dead code); its stale cross-reference to an `AniListApiTest`
    `MediaItemStub` that does not exist was corrected in the same pass
    (PG-042, register premise partially stale — the second "dead branch" it
    counted, `MediaItemAdded`, has no stub in this file in ANY revision).

- Review-campaign majors batch W94 (estate `anilist` majors program):
  - requirements scoping in README and `plugin.json` told users the token
    was "required for list sync" and the username merely "used to pull your
    lists": the configured-gate (`isConfigured()`: token AND username, on
    top of `enabled`) actually refuses EVERY feature — enrichment included —
    so both settings' docs and a new Overview sentence now state the shared
    gate truthfully; the gate itself deliberately stays as strict as the
    code has it (M-ANILIST-02);
  - the enrichment drain can no longer strand its queue: the one-shot timer
    callback ran `drainOne()` unguarded, so any item-level fault (a host
    read error was enough) escaped after the timer was already spent — no
    re-arm, backlog frozen until an unrelated event armed a new timer. The
    tick is now a named `onDrainTick()` that logs-and-drops the faulting
    item and always continues the walk (re-arm while backlog remains, memo
    reset when it empties) — mirroring the protection the periodic sync
    tick has always had (M-ANILIST-06);
  - watchlist sync now walks PAGES: `syncStatusList()` fetched exactly
    page 1 × 50 per status, silently truncating every watchlist beyond 50
    entries; it iterates until a short page under a stable server-side
    `sort: [MEDIA_ID]` (added to the anime list query — the page-stitching
    walk is unsound without a fixed order) with a 100-page backstop whose
    breach is a loud warning, and a mid-walk API failure keeps the per-list
    warning while preserving the pages already synced (M-ANILIST-05);
  - watchlist sync finally WRITES BACK: `resolveMediaItemIdByMalId()` was a
    `return null` stub behind README/composer claims of status sync "back to
    Phlix". It now maps entries through the host repository's
    `findByMetadataField('mal_id', …)` (host master bfd4af98; a runtime
    method_exists probe keeps pre-hook release hosts degrading to a logged
    skip), and the watchlist's own AniList fields merge AUTHORITATIVELY —
    the shared gap-fill path could never move a stored status, so a
    CURRENT→COMPLETED tick silently no-oped even once resolvable
    (M-ANILIST-01);
  - remote 4xx/5xx response bodies no longer ride unbounded into exception
    messages — and from there verbatim into server logs via every caller's
    `'error' => $e->getMessage()`; `HttpClient` now carries only a
    control-char-stripped, newline-free excerpt capped at 500 characters with
    a `[truncated]` marker (placeholder `(no printable body)` when nothing was
    displayable; invalid UTF-8 falls back to printable ASCII), preserving the
    `HTTP <status>` prefix that `AniListApi::isNotFoundFailure()` keys the
    404-to-null mapping on (M-ANILIST-04);
- Review-campaign minors batch W-min7 (estate `anilist minors` program):
  - the phantom `@since 0.14.0` era tags — 19 across all 6 `src/` files, a
    number copied from the old `phlix_min_server_version` — are deleted; the
    plugin has only ever been v0.1.0/v0.2.0 tagged with 0.3.x/0.4.x manifest
    versions (this section is the first honest in-repo era record);
  - `getMediaById()`/`getMediaByMalId()` now honor their documented contract:
    a not-found answer (GraphQL "Not found" or HTTP 404) maps to `null` with a
    debug log instead of throwing and logging a WARNING on the enrichment path;
  - remote values are parsed at the boundary before reaching `metadata_json`:
    `genres` is filtered element-wise to strings, `startDate`/`endDate` are
    shape-checked FuzzyDates, `nextAiringEpisode` is shape-checked, C0/C1 and
    bidi control characters are scrubbed from stored titles/descriptions, and
    cover/banner URLs are constrained to `https:` (M-ANILIST-10/13);
  - `lookupAniListEntry()` drops the never-read `$type` parameter and the
    docblock no longer claims AniList-ID resolution it does not perform;
  - the floating docblock (wrongly documenting a container parameter) above
    `syncIntervalSeconds()` is gone, and `schedulePeriodicSync()` gained a
    truthful one;
  - README and class docs list the five synced statuses
    (CURRENT/COMPLETED/PLANNING/PAUSED/DROPPED; REPEATING is deliberately not
    synced) instead of the old three-name shorthand.
- Review-campaign minors batch W-min8:
  - `HttpClient` reuses one `Workerman\Http\Client` per destination host
    instead of constructing a fresh vendor client (and connection pool) on
    every dispatch; the strict TLS stream context is still built per URL so
    `peer_name` stays correct per host (M-ANILIST-16);
  - `tests/Unit/AniListSyncIntervalTest.php` now declares the PSR-4
    namespace `Phlix\Tests\Unit\Plugins\Metadata\AniList` the composer
    autoload map expects — under its old name it was silently excluded from
    autoload (M-ANILIST-19).
- Review-campaign minors batch W-min9:
  - a numeric `Retry-After` above the 60s ceiling is no longer obeyed
    (misconfigured proxy or hostile header would park the resident cooperative
    worker for minutes); such attempts fall back to the plugin's own
    exponential ladder (M-ANILIST-09);
  - the watchlist write no longer hardcodes the AniList favourite flag to
    `false` although no path ever fetches it — and gap-fill preserves existing
    values, so the lie could never be corrected (rows already carrying the
    stale key are untouched data history; new writes omit it) (M-ANILIST-14);
  - progress sync is monotonic-safe: the viewer's saved `progress` is probed
    via `Media.mediaListEntry` (field verified against the live schema) and a
    non-newer local episode skips the write instead of walking AniList's
    progress backwards; a failed probe fail-softs to the old write (M-ANILIST-15);
  - `mal_id` → entry lookups are memoized for the lifetime of one enrichment
    drain cycle (definitive not-found answers memoized, transient failures
    are not; memo resets when the queue runs dry) (M-ANILIST-15);
  - the cooperative request wait deadlines against monotonic wall time
    (`hrtime(true)`) instead of counting nominal 1ms ticks, which understated
    elapsed time on a loaded worker and stretched the real wait by the tick
    overrun factor (M-ANILIST-17).

### Changed
- Review-campaign majors batch W94 — analysis posture: PHPStan level 9 now
  analyses the host-supplied classes against reality instead of around them
  — `tests/Stub/` carries four analysis-only signature mirrors (the server
  `MediaItem`/`ItemRepository`, Workerman `Timer` v5.2.3 and
  `Http\Client` v3.1.3, wired via `phpstan.neon` `bootstrapFiles`, never
  composer-autoloaded), and `phpstan.baseline.neon` is re-derived: the whole
  12-family `class.notFound` block is cleared (23 entries / 33 errors to
  13 entries / 16), each residual now a justified mixed-JSON or
  deliberate-defense site with per-entry attribution (M-ANILIST-07);
- The composer manifest tells the truth (M-ANILIST-05/07):
  `detain/phlix-shared` is pinned to the consumed API surface at `^0.52.0`
  (the stale `^0.21.0` let every fresh install drift the vendored contract
  layer), `ext-mbstring` is declared for the `mb_*` calls the plugin
  actually makes, and `workerman/http-client` moved to `suggest` — the
  transport is host-supplied at runtime and the plugin must not claim it as
  its own dependency. `/composer.lock` is no longer git-ignored: the lock is
  committed so CI installs exactly the audited graph.
- `AniListApi::setFavorite()` (bool argument the API never echoed back) is
  replaced by `toggleFavorite(int $mediaId)`, issuing the single AniList
  `ToggleFavourite` mutation whose result is the toggled entry (M-ANILIST-18).
- `AniListSettings::$accessToken` is private and read through the explicit
  `accessToken()` accessor; `toArray()` omits the bearer token unless the
  caller passes `$includeSecret`, and only `toSpaArray()` (which never
  carries it) may reach a wire — the host contract is now documented at the
  holder (M-ANILIST-11).

### Added
- This CHANGELOG (`anilist minors` docs-truth batch).
- `tests/Unit/AniListMinorsW8Test.php` pins the composer-manifest truths,
  the `toggleFavorite` shape, and the public-API docblock annotations;
  `AniListHttpClientTlsTest` gained a per-host client-reuse pin (W-min8).
- `tests/Unit/AniListMinorsW9Test.php` pins the Retry-After ceiling, the
  token-custody law, the monotonic progress guard, the drain-cycle lookup
  memo, and the wall-time wait deadline (W-min9).

## 0.4.x era (untagged on master)

- TLS certificate verification is on by default on the outbound AniList
  transport: every request carries a strict full `ssl` stream context
  (`strictTlsContext()`) overriding the vendor pool's lax defaults —
  `983a6e7` (2026-10).
- Dead `Workerman\MySQL` stub removed — the plugin persists through the host
  `ItemRepository` seam only — `f67b76a` (2026-10).
- S440 whole-lock security-audit gate plus its guard test suite ported from the
  sample theme (`scripts/security-audit-check.php`,
  `tests/Unit/SecurityAuditCheckTest.php`) — `a5aea53`, `8391950` (2026-08/10).

## v0.3.x era (2026-07-20 – 2026-07-22, untagged)

- `sync_interval_minutes` is actually honored: the periodic timer stopped
  using the hardcoded 3600s constant — `7f8bbf7` (2026-07-20).
- Real persistence and non-blocking HTTP: metadata writes reach
  `ItemRepository::update()`, the synchronous cURL client was replaced by the
  cooperative Workerman client with transparent HTTP-429 backoff honoring
  `Retry-After`, and the token became a manually-pasted personal access token
  (no host OAuth flow) — `23a821b` (2026-07-21).
- Per-field settings tier classifications (`standard`/`advanced`) and the
  manifest bump to 0.4.0 — `1ddf29a` (2026-07-22).

## v0.2.0 — 2026-07-10 (`64e61a9`, tag `v0.2.0`)

- `ConfigurableInterface` implementation: settings are persisted by the host
  and re-fed to the plugin through `configure()`; enriched settings help text.

## v0.1.0 — 2026-07-09 (`766a834`, tag `v0.1.0`)

- Initial release: AniList GraphQL metadata provider — search, media lookups
  by AniList/MyAnimeList id, watchlist sync, episode progress, synchronous
  cURL transport.
