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

    /**
     * Ceiling on the number of GraphQL error messages composed into one
     * exception message (see {@see self::formatGraphQLErrors()}).
     */
    private const MAX_GRAPHQL_ERROR_MESSAGES = 5;

    /**
     * The shared Media field selection requested by every media-shaped
     * query (search, search, by-id, by-MAL-id).
     *
     * Four near-verbatim copies of this ~26-line block had already drifted
     * — the manga search silently lacked season/seasonYear/
     * nextAiringEpisode that the id lookups carried — and every future
     * field addition was a four-site edit where one missed copy becomes a
     * response-shape bug. One const, interpolated by all four queries:
     * fields a given entry type cannot answer (season on MANGA, chapters
     * on ANIME) come back null, which is what the duplicate copies already
     * produced for their mismatched halves and every consumer guards.
     */
    private const MEDIA_FIELDS = <<<'GRAPHQL'
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
        GRAPHQL;

    private readonly LoggerInterface $logger;

    /**
     * The bearer value is re-checked HERE, at the class that concatenates
     * it into the Authorization header, against the same predicate
     * {@see AniListSettings} enforces ({@see AniListSettings::isWellFormedToken()}
     * — one pattern, no private copy). The production path only ever hands
     * this constructor a settings-vetted token, but the client is a public
     * class and a caller that builds it directly would otherwise ship a
     * CR/LF- or space-bearing value verbatim into a header: a
     * header-splitting primitive. A dirty token is refused loudly at
     * construction, before any request is dispatched — never trimmed or
     * silently dropped, since silently degrading to an unauthenticated
     * client would mask the caller's bug.
     *
     * @param HttpClientInterface $http HTTP client for API requests
     * @param string $accessToken AniList API access token (optional for queries, required for
     *                            mutations); '' means unauthenticated
     * @param LoggerInterface|null $logger Optional PSR-3 logger
     *
     * @throws \InvalidArgumentException When a non-empty token is not a well-formed bearer value
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $accessToken = '',
        ?LoggerInterface $logger = null,
    ) {
        if ($accessToken !== '' && AniListSettings::isWellFormedToken($accessToken) === false) {
            throw new \InvalidArgumentException(
                'AniList access token must be printable ASCII with no whitespace or control characters'
                . ' (a bearer value rides the Authorization header verbatim)'
            );
        }

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
            throw new AniListApiException(
                self::formatGraphQLErrors($response['errors']),
                notFound: self::graphQLErrorsReportNotFound($response['errors']),
            );
        }

        return is_array($response['data'] ?? null) === true ? $response['data'] : [];
    }

    /**
     * Whether a GraphQL errors envelope is the documented not-found answer.
     *
     * AniList answers "no such entry" with an HTTP 200 body whose error
     * message is exactly "Not found". EQUALITY on that documented shape —
     * not a substring scan over composed prose — is the law: an upstream
     * fault that merely mentions the words (a revoked-token notice, a
     * schema complaint, a CDN miss page quoted into the body) must surface
     * as the failure it is, because the lookup wrappers memoize null as
     * definitive absence and enrichment goes silently quiet.
     *
     * @param array<mixed, mixed> $errors Raw decoded `errors` fragment
     */
    private static function graphQLErrorsReportNotFound(array $errors): bool
    {
        foreach ($errors as $error) {
            if (is_array($error) === false) {
                continue;
            }

            /** @var mixed $message */
            $message = $error['message'] ?? null;

            if (is_string($message) === true && trim($message) === 'Not found') {
                return true;
            }
        }

        return false;
    }

    /**
     * Format GraphQL error messages into the exception text.
     *
     * Every message is remote-controlled prose: embedded newlines forge log
     * entries, ANSI/bidi control characters poison terminal viewers, and the
     * size is otherwise unbounded — while every plugin catch site logs
     * `$e->getMessage()`. The HTTP-status path already got the transport's
     * scrub-and-cap (HttpClient); the HTTP-200 GraphQL errors envelope gets
     * the SAME treatment now, per message, and the number of composed
     * messages is capped with the overflow named, so a thousand-entry
     * `errors` array cannot turn one exception into a thousand log segments.
     *
     * @param array<mixed, mixed> $errors Raw decoded `errors` fragment
     *
     * @return string
     */
    private static function formatGraphQLErrors(array $errors): string
    {
        $messages = [];
        $dropped = 0;

        foreach ($errors as $error) {
            if (is_array($error) === false || isset($error['message']) === false) {
                $dropped++;
                continue;
            }

            if (count($messages) >= self::MAX_GRAPHQL_ERROR_MESSAGES) {
                $dropped++;
                continue;
            }

            /** @var mixed $raw */
            $raw = $error['message'];

            if (is_string($raw) === true) {
                $messages[] = HttpClient::safeErrorBodySnippet($raw);

                continue;
            }

            // json_encode() is string|false. A remote-controlled non-string
            // message that cannot be serialized (INF from a `1e999` literal,
            // for instance) used to implode toward empty prose while @return
            // string promised text; name the failure in the message instead
            // of hiding it. The encoded form has its control characters
            // escaped, and it still passes the scrub-and-cap like any other.
            $encoded = json_encode($raw);
            $messages[] = HttpClient::safeErrorBodySnippet(
                is_string($encoded) === true ? $encoded : '(unserializable)'
            );
        }

        if ($messages === []) {
            return 'AniList GraphQL error';
        }

        if ($dropped > 0) {
            $messages[] = '(+' . $dropped . ' more errors)';
        }

        return implode('; ', $messages);
    }

    /**
     * Read one `Page.<field>` list projection off a decoded envelope.
     *
     * json_decode hands back mixed. The four list readers used to carry a
     * decorative `@var array<array<string, mixed>>` promise over a raw
     * nested offset read — a shape claim nothing checked, with matching
     * phpstan offset suppressions in the baseline. This reader NARROWS:
     * a missing or non-object Page, a non-list field, or junk rows answer
     * as the empty/filtered list the docblock actually guarantees, and the
     * suppressions retired with the annotation (register PG-167).
     *
     * @param array<string, mixed> $data  Decoded `data` envelope
     * @param string               $field The Page child list ('media' or 'mediaList')
     *
     * @return list<array<array-key, mixed>>
     */
    private static function readPageList(array $data, string $field): array
    {
        $page = $data['Page'] ?? null;
        if (is_array($page) === false) {
            return [];
        }

        $rows = $page[$field] ?? null;
        if (is_array($rows) === false) {
            return [];
        }

        $entries = [];
        foreach ($rows as $row) {
            if (is_array($row) === true) {
                $entries[] = $row;
            }
        }

        return $entries;
    }

    /**
     * Read the `Media` object projection off a decoded envelope.
     *
     * Same posture as {@see self::readPageList()} for the single-object
     * twin: narrow with is_array instead of annotating an unchecked mixed
     * read. A junk fragment is absence (null), not a type error downstream.
     *
     * @param array<string, mixed> $data Decoded `data` envelope
     *
     * @return array<array-key, mixed>|null
     */
    private static function readMedia(array $data): ?array
    {
        $media = $data['Media'] ?? null;

        return is_array($media) === true ? $media : null;
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
     * @return list<array<array-key, mixed>> List of anime entries
     *
     * @throws AniListApiException
     */
    public function searchAnime(string $search, int $page = 1, int $perPage = 25): array
    {
        $selection = self::MEDIA_FIELDS;
        $query = <<<GRAPHQL
        query SearchAnime(\$search: String, \$page: Int, \$perPage: Int) {
            Page(page: \$page, perPage: \$perPage) {
                media(search: \$search, type: ANIME) {
        {$selection}
                }
            }
        }
        GRAPHQL;

        $data = $this->query($query, [
            'search' => $search,
            'page' => $page,
            'perPage' => min($perPage, 50),
        ]);

        return self::readPageList($data, 'media');
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
     * @return list<array<array-key, mixed>> List of manga entries
     *
     * @throws AniListApiException
     */
    public function searchManga(string $search, int $page = 1, int $perPage = 25): array
    {
        $selection = self::MEDIA_FIELDS;
        $query = <<<GRAPHQL
        query SearchManga(\$search: String, \$page: Int, \$perPage: Int) {
            Page(page: \$page, perPage: \$perPage) {
                media(search: \$search, type: MANGA) {
        {$selection}
                }
            }
        }
        GRAPHQL;

        $data = $this->query($query, [
            'search' => $search,
            'page' => $page,
            'perPage' => min($perPage, 50),
        ]);

        return self::readPageList($data, 'media');
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
     * @return array<array-key, mixed>|null Media entry, or null when no such entry exists
     *
     * @throws AniListApiException On any non-not-found API failure
     */
    public function getMediaById(int $id): ?array
    {
        $selection = self::MEDIA_FIELDS;
        $query = <<<GRAPHQL
        query GetMedia(\$id: Int) {
            Media(id: \$id) {
        {$selection}
            }
        }
        GRAPHQL;

        try {
            $data = $this->query($query, ['id' => $id]);
        } catch (AniListApiException $e) {
            if ($e->isNotFound() === false) {
                throw $e;
            }

            $this->logger->debug('AniList: media not found', ['anilist_id' => $id]);

            return null;
        }

        return self::readMedia($data);
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
     * @return array<array-key, mixed>|null Media entry, or null when no such entry exists
     *
     * @throws AniListApiException On any non-not-found API failure
     */
    public function getMediaByMalId(int $malId): ?array
    {
        $selection = self::MEDIA_FIELDS;
        $query = <<<GRAPHQL
        query GetMediaByMalId(\$idMal: Int) {
            Media(idMal: \$idMal) {
        {$selection}
            }
        }
        GRAPHQL;

        try {
            $data = $this->query($query, ['idMal' => $malId]);
        } catch (AniListApiException $e) {
            if ($e->isNotFound() === false) {
                throw $e;
            }

            $this->logger->debug('AniList: media not found for MyAnimeList id', ['mal_id' => $malId]);

            return null;
        }

        return self::readMedia($data);
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
     * @return list<array<array-key, mixed>> User's media list entries
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

        return self::readPageList($data, 'mediaList');
    }

    /**
     * Get a user's manga list with status filtering.
     *
     * Retained as public client API (host/plugin consumers); not internal
     * dead code.
     *
     * The one page fetched per call is sorted by MEDIA_ID, mirroring
     * {@see self::getUserAnimeList()}: a host consumer that stitches pages
     * of this list inherits the same fixed-order requirement, and paging
     * without it silently duplicates or drops entries.
     *
     * @param string $username AniList username
     * @param string|null $status Filter by status (CURRENT, COMPLETED, PLANNING, PAUSED, DROPPED)
     * @param int $page Page number
     * @param int $perPage Items per page
     *
     * @return list<array<array-key, mixed>> User's media list entries
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
                mediaList(userName: \$userName, {$typeFilter}, sort: [MEDIA_ID]) {
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

        return self::readPageList($data, 'mediaList');
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
