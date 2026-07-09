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

It subscribes to `phlix.library.scan.completed` and `phlix.playback.stopped`.

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

| Setting | Type | Description |
|---|---|---|
| `enabled` | bool | Enable the AniList metadata provider. |
| `access_token` | — | AniList API token (managed automatically). |
| `username` | string | Your AniList username for watchlist sync. |
| `sync_enabled` | bool | Sync watchlist status with AniList. |
| `sync_interval_minutes` | int | How often to sync watchlist (minutes). |
| `auto_match` | bool | Auto-match local media to AniList entries. |

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
