<?php

/**
 * ANL-6 — the remote error body is byte-capped BEFORE the scrub.
 *
 * The >= 400 throw in HttpClient::request() precedes the MAX_RESPONSE_BYTES
 * ceiling check, so safeErrorBodySnippet() used to run its control-char
 * regex, whitespace collapse and mb_strlen() over a hostile 4xx/5xx body of
 * ANY size just to keep its first 500 characters. These tests pin:
 * - the scan window: bytes past MAX_ERROR_BODY_SCAN_BYTES are never read;
 * - the cut is UTF-8 safe (no knock into the ASCII-only fallback) and the
 *   500-character excerpt survives for 3- and 4-byte scripts;
 * - a cut body is `[truncated]` marked even when it scrubs short;
 * - byte-identical 4xx/5xx exception envelopes (status prefix, excerpt,
 *   marker, typed status) for the shapes that existed before the cap;
 * - the 2xx post-buffer ceiling path is untouched (an oversized 4xx still
 *   reports its HTTP status, not the success-path ceiling).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Metadata\AniList;

use Phlix\Plugins\Metadata\AniList\AniListApiException;
use Phlix\Plugins\Metadata\AniList\HttpClient;
use PHPUnit\Framework\TestCase;

final class AniListErrorBodyCapTest extends TestCase
{
    /** Mirror of HttpClient::MAX_ERROR_BODY_SCAN_BYTES (500 * 4 + 16). */
    private const SCAN_BYTES = 2016;

    /**
     * Build an HttpClient whose transport is pinned to one canned response.
     *
     * @param array{status: int, body: string} $response
     */
    private function clientReturning(array $response): HttpClient
    {
        return new class ($response) extends HttpClient {
            /** @param array{status: int, body: string} $canned */
            public function __construct(private array $canned)
            {
                parent::__construct(null);
            }

            protected function sendOnce(string $method, string $url, array $data, array $headers): array
            {
                return [
                    'status' => $this->canned['status'],
                    'body' => $this->canned['body'],
                    'retry_after' => null,
                ];
            }

            protected function tick(): void
            {
            }
        };
    }

    private function thrownBy(int $status, string $body): AniListApiException
    {
        try {
            $this->clientReturning(['status' => $status, 'body' => $body])
                ->post('https://graphql.anilist.co', []);
        } catch (AniListApiException $e) {
            return $e;
        }

        $this->fail('expected AniListApiException for HTTP ' . $status);
    }

    public function test_scan_window_constant_matches_the_documented_budget(): void
    {
        $const = new \ReflectionClassConstant(HttpClient::class, 'MAX_ERROR_BODY_SCAN_BYTES');
        $this->assertSame(self::SCAN_BYTES, $const->getValue());
    }

    public function test_bytes_past_the_scan_window_are_never_read(): void
    {
        $tail = 'SECRET-TAIL-PAST-THE-WINDOW';

        // All-unprintable window: the old code scrubbed the whole body and
        // surfaced the tail; the window now ends before it.
        $this->assertSame(
            '(no printable body)',
            HttpClient::safeErrorBodySnippet(str_repeat("\x01", self::SCAN_BYTES) . $tail)
        );

        // Collapsible whitespace up to the window edge: same story.
        $snippet = HttpClient::safeErrorBodySnippet('head' . str_repeat(' ', self::SCAN_BYTES) . $tail);
        $this->assertStringNotContainsString($tail, $snippet);
        $this->assertSame('head[truncated]', $snippet, 'a cut body is marked even when it scrubs short');
    }

    public function test_a_body_at_exactly_the_window_is_not_cut(): void
    {
        $body = 'x' . str_repeat(' ', self::SCAN_BYTES - 2) . 'y';
        $this->assertSame(self::SCAN_BYTES, strlen($body));
        $this->assertSame('x y', HttpClient::safeErrorBodySnippet($body), 'no marker without a cut');
    }

    public function test_three_byte_script_keeps_the_full_excerpt_on_the_utf8_path(): void
    {
        // 1 ASCII byte shifts every 3-byte sequence so the window edge lands
        // mid-character; mb_strcut must back off rather than emit a broken
        // sequence that would knock the whole excerpt into the ASCII fallback.
        $body = 'a' . str_repeat('あ', 2_000);

        $this->assertSame(
            'a' . str_repeat('あ', 499) . '[truncated]',
            HttpClient::safeErrorBodySnippet($body)
        );
    }

    public function test_four_byte_script_keeps_the_full_excerpt(): void
    {
        $this->assertSame(
            str_repeat("\u{1F600}", 500) . '[truncated]',
            HttpClient::safeErrorBodySnippet(str_repeat("\u{1F600}", 1_000))
        );
    }

    public function test_cut_inside_a_split_sequence_stays_valid_utf8(): void
    {
        // Window edge falls inside the multibyte tail, and the visible text
        // before it is well under the character cap.
        // 'lead' + spaces fill SCAN_BYTES - 1 bytes, so the window's last
        // byte is the first half of the 2-byte 'é'.
        $body = 'lead' . str_repeat(' ', self::SCAN_BYTES - 5) . str_repeat('é', 10);
        $this->assertSame("\xC3", $body[self::SCAN_BYTES - 1]);

        $snippet = HttpClient::safeErrorBodySnippet($body);
        $this->assertTrue(mb_check_encoding($snippet, 'UTF-8'));
        $this->assertSame('lead[truncated]', $snippet);
    }

    // ------------------------------------------------------------------
    // Byte-identical 4xx/5xx exception envelopes.
    // ------------------------------------------------------------------

    public function test_short_4xx_envelope_is_byte_identical(): void
    {
        $e = $this->thrownBy(400, "bad\n\x1b[31mquery\x00 here");

        $this->assertSame('AniList API returned HTTP 400: bad [31mquery here', $e->getMessage());
        $this->assertSame(400, $e->httpStatus());
    }

    public function test_404_envelope_keeps_the_typed_not_found_status(): void
    {
        $e = $this->thrownBy(404, '{"errors":[{"message":"Not Found.","status":404}]}');

        $this->assertSame(
            'AniList API returned HTTP 404: {"errors":[{"message":"Not Found.","status":404}]}',
            $e->getMessage()
        );
        $this->assertSame(404, $e->httpStatus());
    }

    public function test_long_5xx_envelope_is_byte_identical(): void
    {
        $e = $this->thrownBy(500, str_repeat('A', 5_000));

        $this->assertSame(
            'AniList API returned HTTP 500: ' . str_repeat('A', 500) . '[truncated]',
            $e->getMessage()
        );
    }

    public function test_unprintable_4xx_envelope_is_byte_identical(): void
    {
        $this->assertSame(
            'AniList API returned HTTP 422: (no printable body)',
            $this->thrownBy(422, "\x01\x02\n\r  ")->getMessage()
        );
    }

    public function test_oversized_4xx_reports_its_status_not_the_success_ceiling(): void
    {
        // Above MAX_RESPONSE_BYTES (8 MiB): the >= 400 branch still answers
        // first (2xx post-buffer ceiling path unchanged), and the excerpt is
        // drawn from the bounded window only.
        $e = $this->thrownBy(413, str_repeat('Z', 8 * 1024 * 1024 + 1) . 'TAIL');

        $this->assertSame(
            'AniList API returned HTTP 413: ' . str_repeat('Z', 500) . '[truncated]',
            $e->getMessage()
        );
        $this->assertSame(413, $e->httpStatus());
    }

    public function test_the_cut_precedes_the_scrub_in_source(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/HttpClient.php');
        $start = strpos($src, 'public static function safeErrorBodySnippet(');
        $this->assertNotFalse($start);
        $method = substr($src, $start);

        $cut = strpos($method, 'mb_strcut($body, 0, self::MAX_ERROR_BODY_SCAN_BYTES');
        $scrub = strpos($method, 'preg_replace(');
        $this->assertNotFalse($cut, 'the body must be byte-capped before scanning');
        $this->assertNotFalse($scrub);
        $this->assertLessThan($scrub, $cut, 'cap BEFORE the control-char scrub, not after');
    }
}
