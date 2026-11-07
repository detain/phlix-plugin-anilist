# phlix-plugin-anilist

[![tests](https://github.com/detain/phlix-plugin-anilist/actions/workflows/test.yml/badge.svg)](https://github.com/detain/phlix-plugin-anilist/actions/workflows/test.yml)

> AniList metadata provider for [Phlix](https://github.com/detain/phlix-server) —
> anime/manga metadata and watchlist sync, over the AniList GraphQL API.

## Overview

Connects a Phlix server to [AniList](https://anilist.co) to enrich your media
library with anime and manga metadata:

- **Metadata enrichment** — fetches titles, descriptions, genres, average scores,
  and cover images via the AniList GraphQL API.
- **Watchlist sync** — synchronizes your AniList status lists
  (CURRENT/COMPLETED/PLANNING/PAUSED/DROPPED; the rarely-used REPEATING status
  is deliberately not synced) back to Phlix: entries are mapped to local items
  through the host repository's metadata lookup (`mal_id`), and the synced
  AniList fields are written authoritatively for their own namespace. Release
  hosts without the metadata lookup hook (pre-`phlix-server bfd4af98`) skip the
  write-back with a logged notice; enrichment and progress sync are unaffected.
- **Episode progress** — tracks which episodes you've completed for each series.
- **Average score & favorites** — surfaces AniList scores and favourite status.

It subscribes to `phlix.library.item.added` (throttled background enrichment)
and `phlix.playback.stopped` (episode progress sync). Every AniList feature —
the metadata lookups included — requires both the access token and the AniList
username to be configured: with either one missing, nothing runs (see Settings
below). The master toggle gates every AniList path on top of that: with it off
no periodic sync timer is even armed, and sync ticks already in flight refuse
to run — the event-driven features (enrichment and progress write-back) and
the host-armed list sync behave the same way.

## Install

From the Phlix admin **Plugins** section, paste this repo's URL:

```
https://github.com/detain/phlix-plugin-anilist
```

…or from the CLI:

```bash
php bin/phlix plugin:install https://github.com/detain/phlix-plugin-anilist
```

## Settings

Configure these in the Phlix admin **Plugins → Configure** dialog.

| Setting | Type | Required | Secret | Tier | Default | Description |
|---|---|---|---|---|---|---|
| `enabled` | boolean | No | No | `standard` | `false` | Master on/off for AniList sync/matching. |
| `access_token` | string | No | Yes | `standard` | `null` | Personal AniList API access token, pasted in by hand. Create one at [anilist.co/settings/developer](https://anilist.co/settings/developer). Phlix does **not** run an AniList OAuth flow. |
| `username` | string | No | No | `standard` | `""` | Your AniList username. Every AniList feature requires it together with the access token: nothing runs (enrichment included) until both are set. The lists it pulls during sync are addressed by it. |
| `sync_enabled` | boolean | No | No | `advanced` | `true` | Sync your watched status and episode progress with your AniList list. |
| `sync_interval_minutes` | integer | No | No | `advanced` | `60` | How often to sync with AniList — **per worker process**. The host runs a pool of resident workers (~14) and each arms its own sync timer, so an interval of 60 minutes dials AniList roughly every 60/N minutes server-wide, with N full watchlist page-walks per tick. There is no single-designated-syncer coordination inside the plugin. |
| `auto_match` | boolean | No | No | `advanced` | `true` | Auto-match anime to AniList entries using the item's MyAnimeList id (mal_id). |

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse src --level=9
vendor/bin/phpcs src --standard=PSR12
php scripts/security-audit-check.php
```

The entry class is `Phlix\Plugins\Metadata\AniList\AniListPlugin` (implements
`Phlix\Shared\Plugin\LifecycleInterface` and
`Phlix\Shared\Plugin\ConfigurableInterface`). It runs inside a Phlix server host,
which provides the playback/library services at runtime.

## License

MIT — see [LICENSE](LICENSE).
