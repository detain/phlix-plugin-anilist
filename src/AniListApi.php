<?php

/**
 * AniList API Client.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Metadata\AniList;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * AniList GraphQL API v2 client.
 *
 * Implements the AniList API for:
 * - Anime/manga search (full-text, by external ID)
 * - Media-list status management (CURRENT/COMPLETED/PLANNING/PAUSED/DROPPED;
 *   the rarely-used REPEATING status is deliberately not synced)
 * - Episode progress tracking
 * - Average score and favorites
 *
 * @package Phlix\Plugins\Metadata\AniList
 */
class AniListApi
{
    private const BASE_URL = 'https://graphql.anilist.co';

    private readonly LoggerInterface $logger;

    /**
     * @param HttpClientInterface $http HTTP client for API requests
     * @param string $accessToken AniList API access token (optional for queries, required for mutations)
     * @param LoggerInterface|null $logger Optional PSR-3 logger
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $accessToken = '',
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Build the standard AniList HTTP headers.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if ($this->accessToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->accessToken;
        }

        return $headers;
    }

    /**
     * Execute a GraphQL query against the AniList API.
     *
     * @param string $query The GraphQL query string
     * @param array<string, mixed> $variables Query variables
     *
     * @return array<string, mixed> Decoded response data
     *
     * @throws AniListApiException On GraphQL errors
     */
    public function query(string $query, array $variables = []): array
    {
        $response = $this->http->post(self::BASE_URL, [
            'query' => $query,
            'variables' => $variables,
        ], $this->headers());

        if (isset($response['errors']) && is_array($response['errors']) === true) {
            $message = $this->formatGraphQLErrors($response['errors']);
            throw new AniListApiException($message);
        }

        return is_array($response['data'] ?? null) === true ? $response['data'] : [];
    }

    /**
     * Format GraphQL error messages.
     *
     * @param array<array<string, mixed>> $errors
     *
     * @return string
     */
    private function formatGraphQLErrors(array $errors): string
    {
        $messages = [];
        foreach ($errors as $error) {
            if (is_array($error) === true && isset($error['message'])) {
                $messages[] = is_string($error['message']) === true
                    ? $error['message']
                    : json_encode($error['message']);
            }
        }

        return $messages !== [] ? implode('; ', $messages) : 'AniList GraphQL error';
    }

    /**
     * Whether an exception reports a plain not-found answer.
     *
     * AniList signals "no such entry" two ways: a GraphQL error envelope
     * whose message is "Not found" (HTTP 200 body) and a bare HTTP 404 that
     * the transport surfaces as `AniList API returned HTTP 404: …`. Both are
     * absence of data, not API failure, so the lookup wrappers map them to
     * null instead of throwing.
     */
    private static function isNotFoundFailure(AniListApiException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'HTTP 404') || stripos($message, 'not found') !== false;
    }

    /**
     * Search for anime by title.
     *
     * Retained as public client API (host/plugin consumers); not internal
     * dead code.
     *
     * @param string $search Search query
     * @param int $page Page number (1-indexed)
     * @param int $perPage Results per page (max 50)
     *
     * @return array<array<string, mixed>> List of anime entries
     *
     * @throws AniListApiException
     */
    public function searchAnime(string $search, int $page = 1, int $perPage = 25): array
    {
        $query = <<<'GRAPHQL'
        query SearchAnime($search: String, $page: Int, $perPage: Int) {
            Page(page: $page, perPage: $perPage) {
                media(search: $search, type: ANIME) {
                    id
                    idMal
                    title { romaji english native }
                    type
                    format
                    status
                    episodes
                    duration
                    chapters
                    volumes
                    description(asHtml: false)
                    averageScore
                    meanScore
                    popularity
                    favourites
                    genres
                    tags { name rank }
                    startDate { year month day }
                    endDate { year month day }
                    season
                    seasonYear
                    coverImage { large extraLarge }
                    bannerImage
                    externalLinks { url site }
                    relations { edges { relationType node { id title { romaji } type } } }
                    nextAiringEpisode { episode airingAt }
                }
            }
        }
        GRAPHQL;

        $data = $this->query($query, [
            'search' => $search,
            'page' => $page,
            'perPage' => min($perPage, 50),
        ]);

        /** @var array<array<string, mixed>> */
        return $data['Page']['media'] ?? [];
    }

    /**
     * Search for manga by title.
     *
     * Retained as public client API (host/plugin consumers); not internal
     * dead code.
     *
     * @param string $search Search query
     * @param int $page Page number (1-indexed)
     * @param int $perPage Results per page (max 50)
     *
     * @return array<array<string, mixed>> List of manga entries
     *
     * @throws AniListApiException
     */
    public function searchManga(string $search, int $page = 1, int $perPage = 25): array
    {
        $query = <<<'GRAPHQL'
        query SearchManga($search: String, $page: Int, $perPage: Int) {
            Page(page: $page, perPage: $perPage) {
                media(search: $search, type: MANGA) {
                    id
                    idMal
                    title { romaji english native }
                    type
                    format
                    status
                    episodes
                    duration
                    chapters
                    volumes
                    description(asHtml: false)
                    averageScore
                    meanScore
                    popularity
                    favourites
                    genres
                    tags { name rank }
                    startDate { year month day }
                    endDate { year month day }
                    coverImage { large extraLarge }
                    bannerImage
                    externalLinks { url site }
                    relations { edges { relationType node { id title { romaji } type } } }
                }
            }
        }
        GRAPHQL;

        $data = $this->query($query, [
            'search' => $search,
            'page' => $page,
            'perPage' => min($perPage, 50),
        ]);

        /** @var array<array<string, mixed>> */
        return $data['Page']['media'] ?? [];
    }

    /**
     * Look up a media entry by AniList ID.
     *
     * Retained as public client API (host/plugin consumers); not internal
     * dead code.
     *
     * A not-found answer — the GraphQL "Not found" error or an HTTP 404 from
     * the transport — is the documented `null` outcome, not an exception;
     * every other API failure still throws.
     *
     * @param int $id AniList media ID
     *
     * @return array<string, mixed>|null Media entry, or null when no such entry exists
     *
     * @throws AniListApiException On any non-not-found API failure
     */
    public function getMediaById(int $id): ?array
    {
        $query = <<<'GRAPHQL'
        query GetMedia($id: Int) {
            Media(id: $id) {
                id
                idMal
                title { romaji english native }
                type
                format
                status
                episodes
                duration
                chapters
                volumes
                description(asHtml: false)
                averageScore
                meanScore
                popularity
                favourites
                genres
                tags { name rank }
                startDate { year month day }
                endDate { year month day }
                season
                seasonYear
                coverImage { large extraLarge }
                bannerImage
                externalLinks { url site }
                relations { edges { relationType node { id title { romaji } type } } }
                nextAiringEpisode { episode airingAt }
            }
        }
        GRAPHQL;

        try {
            $data = $this->query($query, ['id' => $id]);
        } catch (AniListApiException $e) {
            if (self::isNotFoundFailure($e) === false) {
                throw $e;
            }

            $this->logger->debug('AniList: media not found', ['anilist_id' => $id]);

            return null;
        }

        /** @var array<string, mixed>|null */
        return $data['Media'] ?? null;
    }

    /**
     * Look up a media entry by MyAnimeList ID (idMal).
     *
     * A not-found answer — the GraphQL "Not found" error or an HTTP 404 from
     * the transport — is the documented `null` outcome, not an exception;
     * every other API failure still throws.
     *
     * @param int $malId MyAnimeList ID
     *
     * @return array<string, mixed>|null Media entry, or null when no such entry exists
     *
     * @throws AniListApiException On any non-not-found API failure
     */
    public function getMediaByMalId(int $malId): ?array
    {
        $query = <<<'GRAPHQL'
        query GetMediaByMalId($idMal: Int) {
            Media(idMal: $idMal) {
                id
                idMal
                title { romaji english native }
                type
                format
                status
                episodes
                duration
                chapters
                volumes
                description(asHtml: false)
                averageScore
                meanScore
                popularity
                favourites
                genres
                tags { name rank }
                startDate { year month day }
                endDate { year month day }
                season
                seasonYear
                coverImage { large extraLarge }
                bannerImage
                externalLinks { url site }
                relations { edges { relationType node { id title { romaji } type } } }
                nextAiringEpisode { episode airingAt }
            }
        }
        GRAPHQL;

        try {
            $data = $this->query($query, ['idMal' => $malId]);
        } catch (AniListApiException $e) {
            if (self::isNotFoundFailure($e) === false) {
                throw $e;
            }

            $this->logger->debug('AniList: media not found for MyAnimeList id', ['mal_id' => $malId]);

            return null;
        }

        /** @var array<string, mixed>|null */
        return $data['Media'] ?? null;
    }

    /**
     * The authorized viewer's saved progress for a media entry, if any.
     *
     * AniList scopes `Media.mediaListEntry` to the token's owner (verified
     * against the live schema by introspection: Media.mediaListEntry →
     * MediaList.progress: Int), so this answers "how far has THIS user
     * watched" — the value the monotonic write guard in
     * {@see AniListPlugin::syncProgressToAniList()} compares against.
     *
     * @param int $mediaId AniList media ID
     *
     * @return int|null Current progress, or null when there is no entry or the
     *                  answer is malformed (a missing entry and junk shape are
     *                  both "nothing to compare against")
     *
     * @throws AniListApiException Transport/API failure (caller fail-sots)
     */
    public function getMediaListProgress(int $mediaId): ?int
    {
        $query = <<<'GRAPHQL'
        query GetMediaListProgress($id: Int) {
            Media(id: $id) {
                mediaListEntry {
                    progress
                }
            }
        }
        GRAPHQL;

        $data = $this->query($query, ['id' => $mediaId]);

        /** @var mixed $media */
        $media = $data['Media'] ?? null;
        if (is_array($media) === false) {
            return null;
        }

        /** @var mixed $entry */
        $entry = $media['mediaListEntry'] ?? null;
        if (is_array($entry) === false) {
            return null;
        }

        $progress = $entry['progress'] ?? null;

        return is_int($progress) === true ? $progress : null;
    }

    /**
     * Get a user's anime list with status filtering.
     *
     * The one page fetched per call is sorted by MEDIA_ID: the watchlist walk
     * in {@see AniListPlugin::syncStatusList()} stitches pages together, and
     * page boundaries are only sound under a fixed order.
     *
     * @param string $username AniList username
     * @param string|null $status Filter by status (CURRENT, COMPLETED, PLANNING, PAUSED, DROPPED)
     * @param int $page Page number
     * @param int $perPage Items per page
     *
     * @return array<array<string, mixed>> User's media list entries
     *
     * @throws AniListApiException
     */
    public function getUserAnimeList(string $username, ?string $status = null, int $page = 1, int $perPage = 50): array
    {
        $variables = [
            'userName' => $username,
            'page' => $page,
            'perPage' => min($perPage, 50),
        ];

        $typeFilter = 'type: ANIME';
        if ($status !== null) {
            $variables['status'] = $status;
            $typeFilter = 'type: ANIME, status: $status';
        }

        $query = <<<GRAPHQL
        query GetUserAnimeList(\$userName: String, \$status: MediaListStatus, \$page: Int, \$perPage: Int) {
            Page(page: \$page, perPage: \$perPage) {
                mediaList(userName: \$userName, {$typeFilter}, sort: [MEDIA_ID]) {
                    id
                    status
                    score
                    progress
                    repeat
                    notes
                    startedAt { year month day }
                    completedAt { year month day }
                    media {
                        id
                        idMal
                        title { romaji english native }
                        type
                        format
                        episodes
                        duration
                        coverImage { large extraLarge }
                        bannerImage
                        averageScore
                        genres
                    }
                }
            }
        }
        GRAPHQL;

        $data = $this->query($query, $variables);

        /** @var array<array<string, mixed>> */
        return $data['Page']['mediaList'] ?? [];
    }

    /**
     * Get a user's manga list with status filtering.
     *
     * Retained as public client API (host/plugin consumers); not internal
     * dead code.
     *
     * @param string $username AniList username
     * @param string|null $status Filter by status (CURRENT, COMPLETED, PLANNING, PAUSED, DROPPED)
     * @param int $page Page number
     * @param int $perPage Items per page
     *
     * @return array<array<string, mixed>> User's media list entries
     *
     * @throws AniListApiException
     */
    public function getUserMangaList(string $username, ?string $status = null, int $page = 1, int $perPage = 50): array
    {
        $variables = [
            'userName' => $username,
            'page' => $page,
            'perPage' => min($perPage, 50),
        ];

        $typeFilter = 'type: MANGA';
        if ($status !== null) {
            $variables['status'] = $status;
            $typeFilter = 'type: MANGA, status: $status';
        }

        $query = <<<GRAPHQL
        query GetUserMangaList(\$userName: String, \$status: MediaListStatus, \$page: Int, \$perPage: Int) {
            Page(page: \$page, perPage: \$perPage) {
                mediaList(userName: \$userName, {$typeFilter}) {
                    id
                    status
                    score
                    progress
                    progressVolumes
                    repeat
                    notes
                    startedAt { year month day }
                    completedAt { year month day }
                    media {
                        id
                        idMal
                        title { romaji english native }
                        type
                        format
                        chapters
                        volumes
                        coverImage { large extraLarge }
                        bannerImage
                        averageScore
                        genres
                    }
                }
            }
        }
        GRAPHQL;

        $data = $this->query($query, $variables);

        /** @var array<array<string, mixed>> */
        return $data['Page']['mediaList'] ?? [];
    }

    /**
     * Save (upsert) an anime episode progress entry.
     *
     * @param int $mediaId AniList media ID
     * @param int $episode Episode number watched
     * @param int|null $score Optional score (0-100)
     * @param string|null $status Optional status override
     *
     * @return array<string, mixed> Saved entry
     *
     * @throws AniListApiException
     */
    public function saveEpisodeProgress(int $mediaId, int $episode, ?int $score = null, ?string $status = null): array
    {
        $mutation = <<<'GRAPHQL'
        mutation SaveEpisodeProgress($mediaId: Int, $episode: Int, $score: Int, $status: MediaListStatus) {
            SaveMediaListEntry(mediaId: $mediaId, progress: $episode, score: $score, status: $status) {
                id
                mediaId
                status
                score
                progress
            }
        }
        GRAPHQL;

        $variables = [
            'mediaId' => $mediaId,
            'episode' => $episode,
        ];

        if ($score !== null) {
            $variables['score'] = $score;
        }

        if ($status !== null) {
            $variables['status'] = $status;
        }

        $data = $this->query($mutation, $variables);

        /** @var array<string, mixed> */
        return $data['SaveMediaListEntry'] ?? [];
    }

    /**
     * Flip a media entry's favourite state on the viewer's list.
     *
     * AniList's ToggleFavourite is a toggle, not a setter: the superseded
     * setFavorite(int, bool) promised directional control while BOTH of its
     * branches issued the identical mutation, so the bool was inert and
     * "remove" could actually ADD. The honest surface drops the lie. (True
     * remove-semantics would need SaveFavourites list rewriting — a feature,
     * deliberately out of scope here.)
     *
     * Retained as public client API (host/plugin consumers); not internal
     * dead code.
     *
     * @param int $mediaId AniList media ID
     *
     * @return array<string, mixed> Updated favorite list entry
     *
     * @throws AniListApiException
     */
    public function toggleFavorite(int $mediaId): array
    {
        $mutation = 'mutation ToggleFavorite($mediaId: Int) { ToggleFavourite(mediaId: $mediaId) { media { id } } }';

        $data = $this->query($mutation, ['mediaId' => $mediaId]);

        /** @var array<string, mixed> */
        return $data['ToggleFavourite'] ?? [];
    }

    /**
     * Update the overall status for a media entry.
     *
     * Retained as public client API (host/plugin consumers); not internal
     * dead code.
     *
     * @param int $mediaId AniList media ID
     * @param string $status New status (CURRENT, COMPLETED, PLANNING, PAUSED, DROPPED)
     * @param int|null $score Optional score (0-100)
     * @param int|null $progress Optional episode/chapter progress
     *
     * @return array<string, mixed> Updated entry
     *
     * @throws AniListApiException
     */
    public function updateStatus(int $mediaId, string $status, ?int $score = null, ?int $progress = null): array
    {
        $mutation = <<<'GRAPHQL'
        mutation UpdateStatus($mediaId: Int, $status: MediaListStatus, $score: Int, $progress: Int) {
            SaveMediaListEntry(mediaId: $mediaId, status: $status, score: $score, progress: $progress) {
                id
                mediaId
                status
                score
                progress
            }
        }
        GRAPHQL;

        $variables = [
            'mediaId' => $mediaId,
            'status' => $status,
        ];

        if ($score !== null) {
            $variables['score'] = $score;
        }

        if ($progress !== null) {
            $variables['progress'] = $progress;
        }

        $data = $this->query($mutation, $variables);

        /** @var array<string, mixed> */
        return $data['SaveMediaListEntry'] ?? [];
    }
}
