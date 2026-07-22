# phlix-plugin-anilist

[![tests](https://github.com/detain/phlix-plugin-anilist/actions/workflows/test.yml/badge.svg)](https://github.com/detain/phlix-plugin-anilist/actions/workflows/test.yml)

> AniList metadata provider for [Phlix](https://github.com/detain/phlix-server) —
> anime/manga metadata, watchlist sync, and GraphQL integration.

## Overview

Connects a Phlix server to [AniList](https://anilist.co) to enrich your media
library with anime and manga metadata:

- **Metadata enrichment** — fetches titles, descriptions, genres, average scores,
  and cover images via the AniList GraphQL API.
- **Watchlist sync** — synchronizes your AniList watching/watched/plantowatch
  status back to Phlix.
- **Episode progress** — tracks which episodes you've completed for series.
- **Average score & favorites** — surfaces AniList scores and favourite status.

It subscribes to `phlix.library.item.added` (throttled background enrichment)
and `phlix.playback.stopped` (episode progress sync).

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

| Setting | Type | Required | Default | Description |
|---|---|---|---|---|
| `enabled` | bool | no | `false` | Master on/off for AniList sync/matching. |
| `access_token` | string (secret) | no | — | Personal AniList API access token, pasted in by hand. Create one at [anilist.co/settings/developer](https://anilist.co/settings/developer). Phlix does **not** run an AniList OAuth flow. Required for list sync and writing progress back. |
| `username` | string | no | — | Your AniList username, used to pull your lists during sync. |
| `sync_enabled` | bool | no | `true` | Sync watched/progress with your AniList list. |
| `sync_interval_minutes` | int | no | `60` | How often to sync with AniList. |
| `auto_match` | bool | no | `true` | Auto-match anime to AniList entries using external IDs. |

## Development

```bash
composer install
vendor/bin/phpunit
```

The entry class is `Phlix\Plugins\Metadata\AniList\AniListPlugin` (implements
`Phlix\Shared\Plugin\LifecycleInterface`). It runs inside a Phlix server host,
which provides the playback/library services at runtime.

## License

MIT — see [LICENSE](LICENSE).
