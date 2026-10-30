<?php

/**
 * TLS verification posture tests for the AniList HttpClient.
 *
 * The host-provided workerman/http-client pool defaults TLS verification to
 * OFF (verify_peer=false, verify_peer_name=false, allow_self_signed=true;
 * verified against v3.1.3 ConnectionPool::create(), the pin in phlix-server's
 * composer.lock which is the runtime this plugin loads its client from). The
 * vendor shallow-merges a caller-supplied `context` option over those lax
 * defaults, so a caller must pass a FULL strict `ssl` section to opt in to
 * verification — exactly the idiom phlix-hub shipped for the same vendor
 * family (519c0c0) and phlix-server src/Hub/HttpClient.php mirrors.
 *
 * The AniList personal access token rides every request to
 * https://graphql.anilist.co, so this suite pins that the strict context is
 * actually in effect on the dispatch path — not just defined somewhere.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Metadata\AniList;

use Phlix\Plugins\Metadata\AniList\HttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Test-double for \Workerman\Http\Client: records constructor options and
 * resolves requests synchronously so parent::sendOnce() runs its full
 * construction → request → cooperative-wait path in-process.
 */
final class FakeWorkermanHttpClientForTls
{
    /** @var array<string, mixed> */
    public static array $lastOptions = [];

    public static bool $constructed = false;

    public static int $constructCount = 0;

    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        self::$constructed = true;
        self::$constructCount++;
        self::$lastOptions = $options;
    }

    /** @param array<string, mixed> $options */
    public function request(string $url, array $options = []): void
    {
        $options['success'](new FakeWorkermanHttpResponseForTls());
    }
}

/**
 * Minimal PSR-7-shaped response consumed by sendOnce()'s success callback.
 */
final class FakeWorkermanHttpResponseForTls
{
    public function getStatusCode(): int
    {
        return 200;
    }

    public function getBody(): string
    {
        return '{"data":{}}';
    }

    public function getHeaderLine(string $name): string
    {
        return '';
    }
}

/**
 * TLS posture contract tests.
 */
final class AniListHttpClientTlsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Workerman\Http\Client::class, false)) {
            class_alias(FakeWorkermanHttpClientForTls::class, 'Workerman\Http\Client');
            class_alias(FakeWorkermanHttpResponseForTls::class, 'Workerman\Http\Response');
        }
        FakeWorkermanHttpClientForTls::$constructed = false;
        FakeWorkermanHttpClientForTls::$constructCount = 0;
        FakeWorkermanHttpClientForTls::$lastOptions = [];
    }

    /**
     * One vendor client (and therefore one connection pool / TLS handshake)
     * per remote host: repeated dispatches to the same host must reuse the
     * memoized instance, while a different host still gets its own strict
     * context (no cross-host context bleed).
     */
    public function testRepeatedDispatchesToTheSameHostReuseOneVendorClient(): void
    {
        $client = new class extends HttpClient {
            protected function tick(): void
            {
            }
        };

        $this->invokeSendOnce($client, 'https://graphql.anilist.co');
        $this->invokeSendOnce($client, 'https://graphql.anilist.co');

        self::assertSame(
            1,
            FakeWorkermanHttpClientForTls::$constructCount,
            'the second same-host request must reuse the memoized vendor client, not rebuild the pool'
        );

        $this->invokeSendOnce($client, 'https://other.example.org/x');

        self::assertSame(
            2,
            FakeWorkermanHttpClientForTls::$constructCount,
            'a different host must still get its own client with its own strict context'
        );
        self::assertSame(
            'other.example.org',
            $this->strictSslSection(FakeWorkermanHttpClientForTls::$lastOptions)['peer_name']
        );
    }

    /**
     * The client constructed on the live sendOnce() dispatch path must carry a
     * strict ssl context that overrides the vendor's lax defaults.
     */
    public function testTheDispatchPathConstructsTheClientWithStrictTlsVerification(): void
    {
        $client = new class extends HttpClient {
            protected function tick(): void
            {
                // No-op: the fake resolves synchronously; never stall the suite.
            }
        };

        $result = $this->invokeSendOnce($client, 'https://graphql.anilist.co');

        self::assertTrue(
            FakeWorkermanHttpClientForTls::$constructed,
            'sendOnce() must dispatch through the host-provided Workerman Http\\Client'
        );
        self::assertArrayHasKey(
            'context',
            FakeWorkermanHttpClientForTls::$lastOptions,
            'The Workerman client options must carry a TLS context; the vendor default is verify_peer=false'
        );

        $options = $this->strictSslSection(FakeWorkermanHttpClientForTls::$lastOptions);

        self::assertTrue($options['verify_peer'], 'verify_peer must be true');
        self::assertTrue($options['verify_peer_name'], 'verify_peer_name must be true');
        self::assertFalse($options['allow_self_signed'], 'allow_self_signed must be false');
        self::assertSame('graphql.anilist.co', $options['peer_name'], 'SNI peer identity must be pinned');
        self::assertTrue($options['SNI_enabled'], 'SNI must be explicitly enabled');

        // The verification fix must not silently drop the timeout posture.
        self::assertArrayHasKey('timeout', FakeWorkermanHttpClientForTls::$lastOptions);

        self::assertSame(200, $result['status'], 'the fake transport round-trip must complete');
    }

    /**
     * The peer identity follows the request host; the AniList endpoint itself
     * must be pinned verbatim.
     */
    public function testTheStrictContextPinsThePeerNameToTheRequestHost(): void
    {
        $client = new class extends HttpClient {
            protected function tick(): void
            {
            }
        };

        $this->invokeSendOnce($client, 'https://graphql.anilist.co');
        $options = $this->strictSslSection(FakeWorkermanHttpClientForTls::$lastOptions);
        self::assertSame('graphql.anilist.co', $options['peer_name']);

        FakeWorkermanHttpClientForTls::$lastOptions = [];
        $this->invokeSendOnce($client, 'https://other.example.org/x');
        $options = $this->strictSslSection(FakeWorkermanHttpClientForTls::$lastOptions);
        self::assertSame('other.example.org', $options['peer_name']);
    }

    /**
     * Structural law: exactly one vendor-client construction site exists in
     * HttpClient.php and it carries the strict context. A future lax sibling
     * (second site without context) reddens this pin.
     */
    public function testTheOnlyVendorClientConstructionSiteCarriesTheTlsContext(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/HttpClient.php');

        preg_match_all('/new\s+\\\\Workerman\\\\Http\\\\Client\s*\(/', $source, $matches);

        self::assertCount(1, $matches[0], 'exactly one Workerman Http\\Client construction site is allowed');
        $constructionSite =
            "/\\\$client\s*=\s*new\s+\\\\Workerman\\\\Http\\\\Client\(\s*[\[{][^}\]]*'context'\s*=>\s*self::strictTlsContext\(/";

        self::assertSame(
            1,
            preg_match($constructionSite, $source),
            'the sole construction site must pass the strict TLS context inline'
        );
    }

    /**
     * Run the real parent sendOnce() through the protected seam.
     *
     * @return array{status: int, body: string, retry_after: string|null}
     */
    private function invokeSendOnce(HttpClient $client, string $url): array
    {
        $method = new \ReflectionMethod(HttpClient::class, 'sendOnce');

        /** @var array{status: int, body: string, retry_after: string|null} $result */
        $result = $method->invoke($client, 'POST', $url, [], []);

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function strictSslSection(array $options): array
    {
        $context = $options['context'];
        self::assertIsArray($context);
        self::assertArrayHasKey('ssl', $context, 'the context must provide a full ssl section');
        $ssl = $context['ssl'];
        self::assertIsArray($ssl);

        /** @var array<string, mixed> $ssl */
        return $ssl;
    }
}
