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
- Review-campaign majors batch W94 (estate `anilist` majors program):
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
