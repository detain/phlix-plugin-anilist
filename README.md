# phlix-plugin-anilist

[![tests](https://github.com/detain/phlix-plugin-anilist/actions/workflows/test.yml/badge.svg)](https://github.com/detain/phlix-plugin-anilist/actions/workflows/test.yml)

> AniList metadata provider for [Phlix](https://github.com/detain/phlix-server) —
> anime/manga metadata, watchlist sync, and GraphQL integration.

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
  hosts without the metadata lookup hook (pre-`bfd4af98`) skip the write-back
  with a logged notice; enrichment and progress sync are unaffected.
- **Episode progress** — tracks which episodes you've completed for series.
- **Average score & favorites** — surfaces AniList scores and favourite status.

It subscribes to `phlix.library.item.added` (throttled background enrichment)
and `phlix.playback.stopped` (episode progress sync). Every feature — the
metadata lookups included — runs only when the plugin is enabled AND both the
access token and the AniList username are configured (one shared gate; see
Settings below).

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

| Setting | Type | Tier | Required | Default | Description |
|---|---|---|---|---|---|
| `enabled` | bool | standard | no | `false` | Master on/off for AniList sync/matching. |
| `access_token` | string (secret) | standard | no | — | Personal AniList API access token, pasted in by hand. Create one at [anilist.co/settings/developer](https://anilist.co/settings/developer). Phlix does **not** run an AniList OAuth flow. Required for **every** AniList feature — enrichment, watchlist sync, and progress write-back all sit behind one configured-gate that demands token and username together. |
| `username` | string | standard | no | — | Your AniList username. Required by the same gate as the token (the plugin refuses all work without it); the lists it pulls during sync are addressed by it. |
| `sync_enabled` | bool | advanced | no | `true` | Sync watched/progress with your AniList list. |
| `sync_interval_minutes` | int | advanced | no | `60` | How often to sync with AniList. |
| `auto_match` | bool | advanced | no | `true` | Auto-match anime to AniList entries using external IDs. |

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
