<?php

/**
 * Majors-space consequence tests — review campaign W94 batch.
 *
 * ERA NOTE: the `M-ANILIST-NN` literals in this file are MAJORS-space queue
 * IDs (`.reviews/MAJORS_QUEUE.md`, repo anilist, batch W94). They collide on
 * purpose with the CLOSED minors-space items of the same numbers (and with
 * the minors batch tests AniListMinorsW7/8/9Test.php, whose "M-ANILIST-NN"
 * references mean the MINORS queue). The spaces are distinct item families;
 * this file documents only the majors cures.
 *
 * Covered here so far:
 * - M-ANILIST-04 (majors): a remote 4xx/5xx body may ride into an exception
 *   message — and from there verbatim into logs — only as a control-char
 *   stripped, newline-free, length-bounded excerpt.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Metadata\AniList;

use Phlix\Plugins\Metadata\AniList\AniListApiException;
use Phlix\Plugins\Metadata\AniList\HttpClient;
use PHPUnit\Framework\TestCase;

final class AniListMajorsW94Test extends TestCase
{
    /**
     * Build an HttpClient whose transport is pinned to one canned response.
     *
     * @param array{status: int, body: string, retry_after?: string|null} $response
     */
    private function clientReturning(array $response): HttpClient
    {
        return new class ($response) extends HttpClient {
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

    public function testRemoteErrorBodyControlCharsAndNewlinesNeverReachTheMessage(): void
    {
        $body = "ERR\n\x1b[31mred alert\x00 next\x{202E}spoof\r\nforged entry";

        $client = $this->clientReturning(['status' => 400, 'body' => $body]);

        try {
            $client->post('https://graphql.anilist.co', []);
            $this->fail('expected AniListApiException for HTTP 400');
        } catch (AniListApiException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringStartsWith('AniList API returned HTTP 400: ', $message);
        $this->assertStringNotContainsString("\n", $message, 'newline in a logged message forges log entries');
        $this->assertStringNotContainsString("\r", $message);
        $this->assertStringNotContainsString("\x1b", $message, 'ANSI escapes poison terminal log viewers');
        $this->assertStringNotContainsString("\x00", $message);
        $this->hasNoControlCharacters($message);
        // The printable content survives, collapsed onto one line.
        $this->assertStringContainsString('red alert', $message);
        $this->assertStringContainsString('forged entry', $message);
    }

    public function testRemoteErrorBodyIsLengthBoundedInTheMessage(): void
    {
        $body = str_repeat('A', 5_000);

        $client = $this->clientReturning(['status' => 500, 'body' => $body]);

        try {
            $client->post('https://graphql.anilist.co', []);
            $this->fail('expected AniListApiException for HTTP 500');
        } catch (AniListApiException $e) {
            $message = $e->getMessage();
        }

        $prefix = 'AniList API returned HTTP 500: ';
        $this->assertStringStartsWith($prefix, $message);
        $this->assertLessThanOrEqual(
            strlen($prefix) + 500 + strlen('[truncated]'),
            strlen($message),
            'the excerpt must respect the 500-character ceiling'
        );
        $this->assertStringEndsWith('[truncated]', $message, 'truncation must be visible');
    }

    public function testUnprintableOnlyBodyYieldsPlaceholderNotRawBytes(): void
    {
        $client = $this->clientReturning(['status' => 422, 'body' => "\x01\x02\n\r  "]);

        try {
            $client->post('https://graphql.anilist.co', []);
            $this->fail('expected AniListApiException for HTTP 422');
        } catch (AniListApiException $e) {
            $message = $e->getMessage();
        }

        $this->assertSame('AniList API returned HTTP 422: (no printable body)', $message);
    }

    public function testInvalidUtf8BodyFallsBackToPrintableAscii(): void
    {
        $body = "\xff\xfe garbage \x80\x81 payload";

        $client = $this->clientReturning(['status' => 400, 'body' => $body]);

        try {
            $client->post('https://graphql.anilist.co', []);
            $this->fail('expected AniListApiException for HTTP 400');
        } catch (AniListApiException $e) {
            $message = $e->getMessage();
        }

        $excerpt = substr($message, strlen('AniList API returned HTTP 400: '));
        $this->assertMatchesRegularExpression('/^[\x20-\x7E]*$/', $excerpt);
        $this->assertStringContainsString('garbage', $message);
    }

    public function testHttpStatusPrefixSurvivesForTheNotFoundContract(): void
    {
        // AniListApi::isNotFoundFailure() keys the 404-to-null mapping on the
        // literal 'HTTP 404'; the sanitizer must never eat the prefix.
        $client = $this->clientReturning(['status' => 404, 'body' => '{"errors":[{"message":"Not found"}]}']);

        try {
            $client->post('https://graphql.anilist.co', []);
            $this->fail('expected AniListApiException for HTTP 404');
        } catch (AniListApiException $e) {
            $this->assertStringContainsString('HTTP 404', $e->getMessage());
        }
    }

    /**
     * Assert the message carries no C0/C1 control characters at all
     * (tab/LF/CR included — this is a one-line log payload).
     */
    private function hasNoControlCharacters(string $message): void
    {
        $this->assertSame(
            0,
            preg_match('/[\x00-\x1F\x7F\x{0080}-\x{009F}]/u', $message) ?: 0,
            'exception message must be control-character free'
        );
    }
}
