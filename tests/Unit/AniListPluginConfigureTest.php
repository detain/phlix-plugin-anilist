<?php

/**
 * AniListPluginConfigureTest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Metadata\AniList;

use Phlix\Plugins\Metadata\AniList\AniListPlugin;
use Phlix\Shared\Plugin\ConfigurableInterface;
use Phlix\Shared\Plugin\LifecycleInterface;
use PHPUnit\Framework\TestCase;

/**
 * Confirms the entry class exposes the host settings-injection contract:
 * autowirable (no-arg) construction + {@see ConfigurableInterface}.
 */
final class AniListPluginConfigureTest extends TestCase
{
    public function test_implements_lifecycle_and_configurable(): void
    {
        $plugin = new AniListPlugin();
        $this->assertInstanceOf(LifecycleInterface::class, $plugin);
        $this->assertInstanceOf(ConfigurableInterface::class, $plugin);
    }

    public function test_configure_accepts_settings_without_error(): void
    {
        $plugin = new AniListPlugin();
        $plugin->configure(['enabled' => true, 'username' => 'joe', 'sync_interval_minutes' => 30]);
        $this->assertInstanceOf(AniListPlugin::class, $plugin);
    }
}
