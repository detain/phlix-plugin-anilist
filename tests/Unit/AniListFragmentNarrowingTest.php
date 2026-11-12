<?php

/**
 * ANL-8 — entry sub-object fragments are NARROWED, not baselined.
 *
 * buildMetadataFromEntry() used to read `title { romaji english native }`
 * and `coverImage { large extraLarge }` straight off the mixed json_decode
 * values (`$entry['title'] ?? []`), which phpstan could only tolerate via
 * five "Cannot access offset ... on mixed" baseline suppressions. The reads
 * now go through the private fragment() boundary reader. These tests pin:
 * - a well-formed fragment projects exactly as before;
 * - a missing / null / scalar / list fragment answers as absence (null
 *   fields), never a TypeError or a stray character-offset read;
 * - the five baseline rows stay retired.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Metadata\AniList;

use Phlix\Plugins\Metadata\AniList\AniListPlugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AniListFragmentNarrowingTest extends TestCase
{
    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function buildMetadata(array $entry): array
    {
        $ref = new \ReflectionClass(AniListPlugin::class);
        /** @var AniListPlugin $plugin */
        $plugin = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('logger');
        $prop->setAccessible(true);
        $prop->setValue($plugin, new NullLogger());

        $method = new \ReflectionMethod($plugin, 'buildMetadataFromEntry');
        $method->setAccessible(true);

        /** @var array<string, mixed> */
        return $method->invoke($plugin, $entry);
    }

    public function testWellFormedFragmentsProjectUnchanged(): void
    {
        $meta = $this->buildMetadata([
            'id' => 1,
            'title' => ['romaji' => 'Shingeki no Kyojin', 'english' => 'Attack on Titan', 'native' => '進撃の巨人'],
            'coverImage' => [
                'large' => 'https://s4.anilist.co/large.jpg',
                'extraLarge' => 'https://s4.anilist.co/xl.jpg',
            ],
        ]);

        $this->assertSame('Shingeki no Kyojin', $meta['title_romaji']);
        $this->assertSame('Attack on Titan', $meta['title_english']);
        $this->assertSame('進撃の巨人', $meta['title_native']);
        $this->assertSame('https://s4.anilist.co/large.jpg', $meta['cover_image_large']);
        $this->assertSame('https://s4.anilist.co/xl.jpg', $meta['cover_image_extralarge']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function junkFragments(): array
    {
        return [
            'null' => [null],
            'string' => ['https://evil.example/x.jpg'],
            'int' => [42],
            'bool' => [true],
            'list' => [['https://s4.anilist.co/a.jpg', 'b']],
        ];
    }

    #[DataProvider('junkFragments')]
    public function testJunkFragmentsAnswerAsAbsence(mixed $junk): void
    {
        $meta = $this->buildMetadata(['id' => 1, 'title' => $junk, 'coverImage' => $junk]);

        $this->assertNull($meta['title_romaji']);
        $this->assertNull($meta['title_english']);
        $this->assertNull($meta['title_native']);
        $this->assertNull($meta['cover_image_large']);
        $this->assertNull($meta['cover_image_extralarge']);
        $this->assertSame(1, $meta['anilist_id']);
    }

    public function testMissingFragmentsAnswerAsAbsence(): void
    {
        $meta = $this->buildMetadata(['id' => 7]);

        $this->assertNull($meta['title_romaji']);
        $this->assertNull($meta['cover_image_large']);
    }

    public function testFragmentReaderNarrowsDirectly(): void
    {
        $method = new \ReflectionMethod(AniListPlugin::class, 'fragment');
        $method->setAccessible(true);

        $this->assertSame([], $method->invoke(null, 'scalar'));
        $this->assertSame([], $method->invoke(null, null));
        $this->assertSame(['romaji' => 'x'], $method->invoke(null, ['romaji' => 'x']));
    }

    public function testBaselineCarriesNoOffsetOnMixedSuppressions(): void
    {
        $baseline = (string) file_get_contents(dirname(__DIR__, 2) . '/phpstan.baseline.neon');

        $this->assertStringNotContainsString('Cannot access offset', $baseline);
    }
}
