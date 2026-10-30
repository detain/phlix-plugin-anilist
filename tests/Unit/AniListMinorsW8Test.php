<?php

/**
 * W-min8 minors consequence tests (manifest honesty + surface truth).
 *
 * Covers M-ANILIST-05 (.gitignore no longer contradicts the tracked-lock
 * audit gate), 07 (ext-mbstring declared for the live mb_substr call),
 * 16a (host-supplied workerman transport declared via suggest, NOT require),
 * 18a (shared constraint out of the ^0.21 caret trap), 18b (the setFavorite
 * bool-lie is gone), 18c (src-unused public wrappers annotated as retained
 * client API), and 19 (every test file's namespace complies with the
 * composer.json autoload-dev PSR-4 map).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Metadata\AniList;

use Phlix\Plugins\Metadata\AniList\AniListApi;
use Phlix\Plugins\Metadata\AniList\HttpClientInterface;
use PHPUnit\Framework\TestCase;

final class AniListMinorsW8Test extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function composerJson(): array
    {
        $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    // ---------------------------------------------------------------
    // M-ANILIST-05 — the ignore/gate contradiction
    // ---------------------------------------------------------------

    public function testGitignoreNoLongerIgnoresTheCommittedLock(): void
    {
        $gitignore = (string) file_get_contents(dirname(__DIR__, 2) . '/.gitignore');

        $this->assertStringNotContainsString(
            '/composer.lock',
            $gitignore,
            'the S440 --locked audit (and testTheCommittedLockExists) depend on the tracked lock; an ignore entry is a git rm --cached trap'
        );
        $this->assertFileExists(dirname(__DIR__, 2) . '/composer.lock');
    }

    // ---------------------------------------------------------------
    // M-ANILIST-07 + M-ANILIST-16a + M-ANILIST-18a — composer.json truth
    // ---------------------------------------------------------------

    public function testMbstringIsDeclaredForTheLiveMbSubstrCall(): void
    {
        $require = $this->composerJson()['require'];

        $this->assertArrayHasKey('ext-mbstring', $require);
        $this->assertStringContainsString('mb_substr(', (string) file_get_contents(dirname(__DIR__, 2) . '/src/AniListPlugin.php'));
    }

    public function testTheWorkermanTransportIsSuggestedNotRequired(): void
    {
        $manifest = $this->composerJson();

        $this->assertArrayHasKey('workerman/http-client', $manifest['suggest'] ?? [], 'the host-supplied transport must be declared');

        foreach (['require', 'require-dev'] as $section) {
            $keys = implode(array_keys($manifest[$section] ?? []));
            $this->assertStringNotContainsString('workerman', $keys, "$section must not require the host-supplied transport — standalone installs/CI resolve it from the phlix host only");
        }
    }

    public function testTheSharedConstraintLeftTheCaret021Trap(): void
    {
        $require = $this->composerJson()['require'];

        $this->assertArrayHasKey('detain/phlix-shared', $require);
        $this->assertNotSame('^0.21.0', $require['detain/phlix-shared']);

        $lock = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.lock'), true);
        $this->assertIsArray($lock);
        foreach ($lock['packages'] as $package) {
            if ($package['name'] === 'detain/phlix-shared') {
                $this->assertSame('v0.52.0', $package['version'], 'the tracked lock must carry the estate-current shared era');
            }
        }
    }

    // ---------------------------------------------------------------
    // M-ANILIST-18b — the directional bool-lie surface is gone
    // ---------------------------------------------------------------

    public function testTheApiExposesToggleNotTheLie(): void
    {
        $this->assertFalse(method_exists(AniListApi::class, 'setFavorite'));

        $toggle = new \ReflectionMethod(AniListApi::class, 'toggleFavorite');
        $this->assertSame(1, $toggle->getNumberOfParameters(), 'the inert direction bool must not come back');
    }

    // ---------------------------------------------------------------
    // M-ANILIST-18c — src-unused public wrappers are annotated
    // ---------------------------------------------------------------

    public function testRetainedPublicApiWrappersAreAnnotated(): void
    {
        $apiSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/src/AniListApi.php');
        $ifaceSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/src/HttpClientInterface.php');

        $this->assertGreaterThanOrEqual(6, substr_count($apiSrc, 'Retained as public client API'));
        $this->assertStringContainsString('Retained as public client API', $ifaceSrc);
    }

    // ---------------------------------------------------------------
    // M-ANILIST-19 — every test file complies with the PSR-4 map
    // ---------------------------------------------------------------

    public function testEveryTestFileNamespaceMatchesTheAutoloadDevMap(): void
    {
        $expected = 'Phlix\\Tests\\Unit\\Plugins\\Metadata\\AniList';

        $files = glob(dirname(__DIR__) . '/Unit/*.php');
        $this->assertIsArray($files);

        foreach ($files as $file) {
            if (basename($file) === 'bootstrap.php' || preg_match('/Test\.php$/', $file) !== 1) {
                continue;
            }

            $src = (string) file_get_contents($file);
            $this->assertMatchesRegularExpression(
                '/^namespace ' . preg_quote($expected, '/') . ';$/m',
                $src,
                basename($file) . ' deviates from the composer autoload-dev PSR-4 map; under optimize-autoloader it would be unreachable'
            );
        }
    }
}
