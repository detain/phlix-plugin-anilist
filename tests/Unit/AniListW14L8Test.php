<?php

/**
 * Consequence tests for the w14L8 fix batch (register anchors
 * plugin-anilist__srphp.md #6/#7/#16 and plugin-anilist__secaudit.md #1/#5/#7).
 *
 * Laws pinned here:
 * 1. not-found is a TYPED signal on AniListApiException (an HTTP 404 status,
 *    or a GraphQL envelope whose message equals the documented "Not found")
 *    — never a substring scan over composed, remote-influenced prose;
 * 2. the four media-shaped queries share one field-selection const, so the
 *    search/list copies can no longer drift against each other;
 * 3. Page/Media envelope reads go through typed boundary readers (is_array
 *    shape guards), not decorative @var annotations over unchecked offsets;
 * 4. GraphQL error prose enters the exception channel through the same
 *    scrub-and-cap the transport already applies to 4xx/5xx bodies, and the
 *    number of composed messages is bounded;
 * 5. a bearer token must match a printable whitespace-free pattern to be
 *    stored — a header-splitting paste is dropped fail-closed at the parse
 *    boundary and refused outright by the constructor;
 * 6. a success-path response body above the declared ceiling fails the
 *    request instead of inflating resident-worker memory through json_decode.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Metadata\AniList;

use Phlix\Plugins\Metadata\AniList\AniListApi;
use Phlix\Plugins\Metadata\AniList\AniListApiException;
use Phlix\Plugins\Metadata\AniList\AniListSettings;
use Phlix\Plugins\Metadata\AniList\HttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AniListW14L8Test extends TestCase
{
    /**
     * @param list<array<string, mixed>> $responses Queued decoded envelopes
     */
    private function apiReturning(array $responses): AniListApi
    {
        return new AniListApi(new MockHttpClient($responses), 'test-access-token', new NullLogger());
    }

    /**
     * HttpClient with a canned transport answer (the W94 clientReturning
     * pattern — sendOnce/tick are the sanctioned seams).
     *
     * @param array{status: int, body: string, retry_after?: string|null} $canned
     */
    private function transportReturning(array $canned): HttpClient
    {
        return new class ($canned) extends HttpClient {
            public function __construct(private array $canned)
            {
                parent::__construct(null);
            }

            protected function sendOnce(string $method, string $url, array $data, array $headers): array
            {
                return [
                    'status' => $this->canned['status'],
                    'body' => $this->canned['body'],
                    'retry_after' => $this->canned['retry_after'] ?? null,
                ];
            }

            protected function tick(): void
            {
            }
        };
    }

    // ------------------------------------------------------------------
    // PG-165: not-found classification is typed, never message prose.
    // ------------------------------------------------------------------

    public function test_documented_not_found_envelope_still_maps_to_null(): void
    {
        // Positive control for the rotation: the documented shape (an error
        // message exactly "Not found") must keep answering absence with null.
        $api = $this->apiReturning([['errors' => [['message' => 'Not found']]]]);

        $this->assertNull($api->getMediaById(1));
    }

    public function test_remote_prose_mentioning_not_found_is_not_definitive_absence(): void
    {
        // The retired classifier stripos-matched "not found" across the whole
        // exception message — which embeds remote error prose verbatim. A CDN
        // complaint or a transient blurb that merely contains the words was
        // mapped to null and memoized as "this media has no AniList entry",
        // silently suppressing enrichment while the real fault went unreported.
        $api = $this->apiReturning([[
            'errors' => [['message' => 'The requested resource was not found in the edge cache; retry later']],
        ]]);

        $this->expectException(AniListApiException::class);
        $api->getMediaById(1);
    }

    public function test_revoked_token_prose_with_not_found_words_still_throws_for_malid_lookup(): void
    {
        $api = $this->apiReturning([[
            'errors' => [['message' => 'Access token revoked — the referenced object was not found']],
        ]]);

        $this->expectException(AniListApiException::class);
        $api->getMediaByMalId(999);
    }

    public function test_4xx_exception_carries_the_http_status_as_typed_data(): void
    {
        $client = $this->transportReturning(['status' => 404, 'body' => '{"errors":[{"message":"Not found"}]}']);

        try {
            $client->post('https://graphql.anilist.co', []);
            $this->fail('expected AniListApiException for HTTP 404');
        } catch (AniListApiException $e) {
            $this->assertSame(404, $e->httpStatus(), 'the answering status is typed data on the exception');
            $this->assertTrue($e->isNotFound());
            $this->assertStringContainsString(
                'HTTP 404',
                $e->getMessage(),
                'the human-readable prefix stays in the message even though classification no longer reads it'
            );
        }
    }

    public function test_transport_404_maps_to_null_through_the_typed_status(): void
    {
        $api = new AniListApi($this->transportReturning(['status' => 404, 'body' => 'gone']), 't', new NullLogger());

        $this->assertNull($api->getMediaById(1));
    }

    public function test_a_5xx_never_classifies_as_absence(): void
    {
        $client = $this->transportReturning(['status' => 500, 'body' => 'internal']);
        $api = new AniListApi($client, 't', new NullLogger());

        try {
            $api->getMediaById(1);
            $this->fail('expected the 500 to surface, not to map to null');
        } catch (AniListApiException $e) {
            $this->assertSame(500, $e->httpStatus());
            $this->assertFalse($e->isNotFound());
        }
    }

    // ------------------------------------------------------------------
    // PG-166: one shared Media selection for all four media queries.
    // ------------------------------------------------------------------

    public function test_the_four_media_queries_share_one_field_selection(): void
    {
        $selection = (new \ReflectionClass(AniListApi::class))->getConstant('MEDIA_FIELDS');
        $this->assertIsString($selection, 'one shared Media field selection const must exist');

        $lookups = [
            'searchAnime' => static function (AniListApi $api): void {
                $api->searchAnime('One Piece');
            },
            'searchManga' => static function (AniListApi $api): void {
                $api->searchManga('Berserk');
            },
            'getMediaById' => static function (AniListApi $api): void {
                $api->getMediaById(1);
            },
            'getMediaByMalId' => static function (AniListApi $api): void {
                $api->getMediaByMalId(1);
            },
        ];

        foreach ($lookups as $name => $call) {
            $http = new MockHttpClient([['data' => []]]);
            $call(new AniListApi($http, 't', new NullLogger()));

            $this->assertStringContainsString(
                (string) $selection,
                (string) ($http->lastData['query'] ?? ''),
                "$name must interpolate the shared selection, not carry its own copy"
            );
        }
    }

    public function test_search_manga_selection_grew_the_drifted_fields(): void
    {
        // The four copies had already drifted: the manga search silently
        // lacked season/seasonYear/nextAiringEpisode that the id lookups
        // carried. The shared const gives every media query the same shape;
        // fields a MANGA entry cannot answer come back null, which every
        // consumer already guards.
        $http = new MockHttpClient([['data' => []]]);
        (new AniListApi($http, 't', new NullLogger()))->searchManga('Berserk');

        $query = (string) ($http->lastData['query'] ?? '');
        $this->assertStringContainsString('seasonYear', $query);
        $this->assertStringContainsString('nextAiringEpisode { episode airingAt }', $query);
    }

    public function test_media_selection_carries_no_unconsumed_fields(): void
    {
        // ANL-15: tags and externalLinks were selected on every media query
        // but never read by buildMetadataFromEntry() — dead response weight
        // and query complexity. They stay out until a consumer reads them.
        $selection = (string) (new \ReflectionClass(AniListApi::class))->getConstant('MEDIA_FIELDS');

        $this->assertStringNotContainsString('tags', $selection);
        $this->assertStringNotContainsString('externalLinks', $selection);

        $http = new MockHttpClient([['data' => []]]);
        (new AniListApi($http, 't', new NullLogger()))->getMediaByMalId(1);
        $query = (string) ($http->lastData['query'] ?? '');
        $this->assertStringNotContainsString('tags {', $query);
        $this->assertStringNotContainsString('externalLinks', $query);
        // Control: the fields the metadata builder does read are still there.
        $this->assertStringContainsString('genres', $query);
        $this->assertStringContainsString('bannerImage', $query);
    }

    // ------------------------------------------------------------------
    // PG-167: typed boundary readers replace decorative @var-over-offset.
    // ------------------------------------------------------------------

    public function test_non_object_rows_are_dropped_from_page_lists(): void
    {
        // The reader GUARANTEES list<array> — the decorative annotation only
        // promised it. A junk row in the envelope is now dropped, not typed
        // through to every consumer under a false shape claim.
        $api = $this->apiReturning([[
            'data' => ['Page' => ['media' => [['id' => 1], 'junk', null]]],
        ]]);

        $this->assertSame([['id' => 1]], $api->searchAnime('x'));
    }

    public function test_non_list_page_envelopes_read_as_empty_lists(): void
    {
        // Control: the junk-envelope answer was [] before and stays [] — the
        // cure keeps behavior and removes the unchecked-offset suppressions.
        $api = $this->apiReturning([
            ['data' => ['Page' => null]],
            ['data' => ['Page' => ['media' => 'not-a-list']]],
            ['data' => ['Page' => ['mediaList' => 42]]],
        ]);

        $this->assertSame([], $api->searchAnime('x'));
        $this->assertSame([], $api->searchManga('x'));
        $this->assertSame([], $api->getUserAnimeList('u'));
    }

    public function test_media_reads_narrow_object_shapes_through_the_reader(): void
    {
        $api = $this->apiReturning([
            ['data' => ['Media' => ['id' => 7]]],
            ['data' => ['Media' => 'junk']],
        ]);

        $this->assertSame(['id' => 7], $api->getMediaById(7));
        $this->assertNull($api->getMediaById(8), 'a non-object Media fragment reads as absence, not a type error');
    }

    public function test_the_decorative_var_annotations_and_the_unshaped_wait_state_are_gone(): void
    {
        // Source scan (house idiom): the annotations that claimed a proven
        // shape without checking it must be gone from both files, and the
        // cooperative-wait state must carry a declared shape.
        $apiSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/src/AniListApi.php');
        $httpSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/src/HttpClient.php');

        $this->assertSame(
            0,
            preg_match_all('/@var array<array<string, mixed>> \*/', $apiSrc),
            'four sites carried an unchecked list-shape promise over a raw offset read; the readers replace them'
        );
        $this->assertSame(
            0,
            preg_match_all('/@var array<string, mixed>\|null \*/', $apiSrc),
            'the two Media object annotations are replaced by the same reader posture'
        );
        $this->assertMatchesRegularExpression(
            '/@return list<array<array-key, mixed>>/',
            $apiSrc,
            'the page readers document the real narrowed shape they return'
        );
        $this->assertMatchesRegularExpression(
            '/@var array\{done: bool, status: int, body: string, '
                . 'retry_after: string\|null, error: string\|null\} \$state/',
            $httpSrc,
            'the wait-state shape was only ever proven by the happy path; it is now declared'
        );
        $this->assertMatchesRegularExpression(
            '/is_string\(\$retryAfter\) === true/',
            $httpSrc,
            'the Retry-After read narrows at the seam instead of pushing mixed into the declared shape'
        );
    }

    // ------------------------------------------------------------------
    // PG-168: GraphQL error prose enters the message scrubbed and capped.
    // ------------------------------------------------------------------

    public function test_graphql_error_prose_enters_the_message_scrubbed_and_capped(): void
    {
        // Embedded newlines forge log entries, ANSI/bidi control characters
        // poison terminal viewers, and the size was unbounded — while every
        // plugin catch site logs $e->getMessage(). The HTTP-200 GraphQL
        // errors envelope must get the same treatment the transport already
        // gives 4xx/5xx bodies.
        $poison = "rate\n\x1b[31mlimited\x00 again\u{202E}" . str_repeat('x', 2000);
        $api = $this->apiReturning([['errors' => [['message' => $poison]]]]);

        try {
            $api->getMediaById(1);
            $this->fail('expected AniListApiException for the GraphQL error envelope');
        } catch (AniListApiException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringNotContainsString("\n", $message);
        $this->assertStringNotContainsString("\x1b", $message);
        $this->assertStringNotContainsString("\u{202E}", $message);
        $this->assertStringContainsString('rate', $message, 'clean prose must survive the treatment');
        $this->assertStringContainsString('[truncated]', $message);
        $this->assertLessThanOrEqual(511, mb_strlen($message), 'excerpt cap + marker');
    }

    public function test_the_envelope_carries_a_bounded_number_of_messages(): void
    {
        $errors = [];
        for ($i = 0; $i < 12; $i++) {
            $errors[] = ['message' => "err $i"];
        }
        $api = $this->apiReturning([['errors' => $errors]]);

        try {
            $api->getMediaById(1);
            $this->fail('expected AniListApiException for the GraphQL error envelope');
        } catch (AniListApiException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringContainsString('err 0', $message);
        $this->assertStringContainsString('err 4', $message);
        $this->assertStringNotContainsString('err 5 ', $message . ' ', 'carried messages are capped');
        $this->assertStringContainsString('(+7 more errors)', $message, 'the overflow is named, not silently dropped');
    }

    // ------------------------------------------------------------------
    // PG-169: the bearer token is guarded at the settings parse boundary.
    // ------------------------------------------------------------------

    public function test_pasted_token_is_trimmed_of_paste_whitespace(): void
    {
        $settings = AniListSettings::fromArray(['access_token' => "  test-token-123\n", 'username' => 'u']);

        $this->assertSame('test-token-123', $settings->accessToken(), 'paste-time whitespace is trimmed, not fatal');
    }

    public function test_a_token_with_control_or_space_bytes_never_stores(): void
    {
        // The value is concatenated into the Authorization header, so an
        // embedded CR/LF or space is a header-splitting primitive. It can
        // only arrive by a self-inflicted malicious paste by an admin — the
        // cheap invariant belongs at the parse boundary anyway: a dirty
        // paste fails CLOSED to "not configured", it is never replayed.
        foreach (["bad\x00token", 'bad token', "bad\r\ntoken: split", "token\u{202E}"] as $dirty) {
            $settings = AniListSettings::fromArray(['access_token' => $dirty, 'username' => 'u']);

            $this->assertFalse($settings->hasToken(), 'a dirty paste must not count as a token');
            $this->assertNull($settings->accessToken());
        }
    }

    public function test_the_constructor_refuses_a_dirty_token(): void
    {
        // The fromArray() parse boundary only ever hands the constructor a
        // clean (or null) value; direct API consumers that skip the boundary
        // get the loud refusal the class invariant deserves.
        $this->expectException(\InvalidArgumentException::class);

        new AniListSettings(accessToken: "bearer\nHost: evil");
    }

    public function test_a_clean_token_round_trips_unchanged(): void
    {
        $settings = AniListSettings::fromArray(['access_token' => 'AbCd123-_~', 'username' => 'u']);

        $this->assertSame(
            'AbCd123-_~',
            $settings->toArray(true)['access_token'],
            'the guard must not alter valid tokens'
        );
    }

    // ------------------------------------------------------------------
    // PG-170: the success-path response body has a ceiling.
    // ------------------------------------------------------------------

    public function test_a_success_body_above_the_ceiling_fails_the_request(): void
    {
        $ceiling = (new \ReflectionClass(HttpClient::class))->getConstant('MAX_RESPONSE_BYTES');
        $this->assertIsInt($ceiling);

        $body = '{"data":{"Media":{"id":1,"desc":"' . str_repeat('x', $ceiling + 1) . '"}}}';
        $api = new AniListApi($this->transportReturning(['status' => 200, 'body' => $body]), 't', new NullLogger());

        try {
            $api->getMediaById(1);
            $this->fail('expected the ceiling to refuse a flood-capable success body');
        } catch (AniListApiException $e) {
            $this->assertStringContainsString('8 MiB response ceiling', $e->getMessage());
            $this->assertFalse($e->isNotFound(), 'a refusal above the ceiling is an API failure, never absence');
        }
    }

    public function test_a_body_at_the_ceiling_still_decodes(): void
    {
        $ceiling = (int) (new \ReflectionClass(HttpClient::class))->getConstant('MAX_RESPONSE_BYTES');

        $pad = str_repeat('x', $ceiling - strlen('{"data":{"Media":{"id":1,"desc":"' . '"}}}'));
        $api = new AniListApi(
            $this->transportReturning(['status' => 200, 'body' => '{"data":{"Media":{"id":1,"desc":"' . $pad . '"}}}']),
            't',
            new NullLogger(),
        );

        $media = $api->getMediaById(1);

        $this->assertIsArray($media);
        $this->assertSame(1, $media['id']);
    }
}
