<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\AniList;

use Phlix\Plugins\Metadata\AniList\AniListPlugin;
use Phlix\Plugins\Metadata\AniList\AniListSettings;
use PHPUnit\Framework\TestCase;

/**
 * Consequence tests for `sync_interval_minutes`.
 *
 * ## The defect
 *
 * The value was parsed into {@see AniListSettings::$syncIntervalMinutes} and then
 * IGNORED: `schedulePeriodicSync()` armed its timer with the hardcoded
 * `SYNC_INTERVAL_SEC = 3600` constant, so changing the interval in the admin UI
 * did nothing while the help text advertised it as working.
 */
final class AniListSyncIntervalTest extends TestCase
{
    private function resolve(int $minutes): int
    {
        $settings = new AniListSettings(
            accessToken: 'a',
            username: 'u',
            syncIntervalMinutes: $minutes,
        );

        $ref = new \ReflectionClass(AniListPlugin::class);
        /** @var AniListPlugin $plugin */
        $plugin = $ref->newInstanceWithoutConstructor();

        $prop = $ref->getProperty('settings');
        $prop->setAccessible(true);
        $prop->setValue($plugin, $settings);

        $m = $ref->getMethod('syncIntervalSeconds');
        $m->setAccessible(true);

        /** @var int $seconds */
        $seconds = $m->invoke($plugin);

        return $seconds;
    }

    public function test_configured_interval_is_honoured(): void
    {
        $this->assertSame(15 * 60, $this->resolve(15));
        $this->assertSame(240 * 60, $this->resolve(240));
    }

    public function test_non_positive_interval_falls_back_to_the_default(): void
    {
        $this->assertSame(3600, $this->resolve(0));
        $this->assertSame(3600, $this->resolve(-1));
    }

    /**
     * CONSEQUENCE: the timer call site must use the resolved value. A correct
     * resolver that nothing calls is the original defect's exact shape.
     */
    public function test_timer_uses_the_resolved_interval_not_the_constant(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/AniListPlugin.php');
        $this->assertIsString($src);

        $this->assertStringContainsString('Timer::add($this->syncIntervalSeconds()', $src);
        $this->assertStringNotContainsString('Timer::add(self::SYNC_INTERVAL_SEC', $src);
    }
}
