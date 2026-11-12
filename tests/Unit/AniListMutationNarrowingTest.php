<?php

/**
 * ANL-7 — mutation returns are NARROWED, not annotated.
 *
 * saveEpisodeProgress(), toggleFavorite() and updateStatus() used to return
 * `$data[<field>] ?? []` under a decorative `@var array<string, mixed>`. A
 * non-array fragment under the mutation key (scalar, string, bool) escaped
 * the declared array return as a TypeError at the caller, and a JSON list
 * answered with int keys despite the string-keyed promise. These tests pin
 * the readMutationPayload() boundary reader:
 * - a non-array value under the key answers as the empty payload [];
 * - a missing key / null answers as [] (pre-existing absence semantics);
 * - only string-keyed members survive (int keys of a list are dropped);
 * - a well-formed object passes through unchanged.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Metadata\AniList;

use Phlix\Plugins\Metadata\AniList\AniListApi;
use Phlix\Plugins\Metadata\AniList\HttpClientInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AniListMutationNarrowingTest extends TestCase
{
    /**
     * Build an AniListApi whose transport answers every POST with one body.
     *
     * @param array<string, mixed> $response
     */
    private static function apiAnswering(array $response): AniListApi
    {
        $http = new class ($response) implements HttpClientInterface {
            /** @param array<string, mixed> $response */
            public function __construct(private readonly array $response)
            {
            }

            public function post(string $url, array $data, array $headers = []): array
            {
                return $this->response;
            }

            public function get(string $url, array $params = [], array $headers = []): array
            {
                return $this->response;
            }
        };

        return new AniListApi($http, 'test-access-token', new NullLogger());
    }

    /**
     * @return array<string, array{0: string, 1: callable(AniListApi): array<string, mixed>}>
     */
    public static function mutations(): array
    {
        return [
            'saveEpisodeProgress' => [
                'SaveMediaListEntry',
                static fn (AniListApi $api): array => $api->saveEpisodeProgress(1, 5),
            ],
            'toggleFavorite' => [
                'ToggleFavourite',
                static fn (AniListApi $api): array => $api->toggleFavorite(1),
            ],
            'updateStatus' => [
                'SaveMediaListEntry',
                static fn (AniListApi $api): array => $api->updateStatus(1, 'COMPLETED', 85, 12),
            ],
        ];
    }

    /**
     * @return list<mixed>
     */
    private static function nonArrayFragments(): array
    {
        return ['junk', 42, 1.5, true, false, null];
    }

    /**
     * @param callable(AniListApi): array<string, mixed> $call
     */
    #[DataProvider('mutations')]
    public function testNonArrayUnderKeyAnswersEmptyPayload(string $field, callable $call): void
    {
        foreach (self::nonArrayFragments() as $fragment) {
            $result = $call(self::apiAnswering(['data' => [$field => $fragment]]));

            $this->assertSame([], $result, sprintf(
                '%s fragment %s must narrow to [] (no TypeError escape)',
                $field,
                var_export($fragment, true)
            ));
        }
    }

    /**
     * @param callable(AniListApi): array<string, mixed> $call
     */
    #[DataProvider('mutations')]
    public function testMissingKeyAnswersEmptyPayload(string $field, callable $call): void
    {
        $this->assertSame([], $call(self::apiAnswering(['data' => []])));
        $this->assertSame([], $call(self::apiAnswering(['data' => ['Other' => ['id' => 1]]])));
    }

    /**
     * @param callable(AniListApi): array<string, mixed> $call
     */
    #[DataProvider('mutations')]
    public function testOnlyStringKeyedMembersSurvive(string $field, callable $call): void
    {
        $list = $call(self::apiAnswering(['data' => [$field => [['id' => 1], 'x']]]));
        $this->assertSame([], $list, 'a JSON list has no string keys — nothing survives');

        $mixed = $call(self::apiAnswering(['data' => [$field => ['id' => 7, 0 => 'drop', 'status' => 'CURRENT']]]));
        $this->assertSame(['id' => 7, 'status' => 'CURRENT'], $mixed);
    }

    /**
     * @param callable(AniListApi): array<string, mixed> $call
     */
    #[DataProvider('mutations')]
    public function testWellFormedObjectPassesThroughUnchanged(string $field, callable $call): void
    {
        $payload = [
            'id' => 1,
            'mediaId' => 1,
            'status' => 'COMPLETED',
            'score' => 85,
            'progress' => 12,
            'media' => ['id' => 1],
        ];

        $this->assertSame($payload, $call(self::apiAnswering(['data' => [$field => $payload]])));
    }

    public function testDecorativeVarAnnotationsAreGoneFromMutationReturns(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/AniListApi.php');

        $this->assertDoesNotMatchRegularExpression(
            '#/\*\* @var array<string, mixed> \*/\s*return \$data\[#',
            $source,
            'mutation returns must narrow through readMutationPayload(), not annotate'
        );
        $this->assertSame(3, substr_count($source, 'return self::readMutationPayload($data, '));
    }
}
