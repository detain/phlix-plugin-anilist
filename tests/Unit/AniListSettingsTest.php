<?php

/**
 * AniList Settings Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Metadata\AniList;

use Phlix\Plugins\Metadata\AniList\AniListSettings;
use PHPUnit\Framework\TestCase;

final class AniListSettingsTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $settings = new AniListSettings();

        $this->assertNull($settings->accessToken);
        $this->assertSame('', $settings->username);
        $this->assertTrue($settings->syncEnabled);
        $this->assertSame(60, $settings->syncIntervalMinutes);
        $this->assertTrue($settings->autoMatch);
    }

    public function testFromArray(): void
    {
        $data = [
            'access_token' => 'test-token-123',
            'username' => 'testuser',
            'sync_enabled' => false,
            'sync_interval_minutes' => 120,
            'auto_match' => false,
        ];

        $settings = AniListSettings::fromArray($data);

        $this->assertSame('test-token-123', $settings->accessToken);
        $this->assertSame('testuser', $settings->username);
        $this->assertFalse($settings->syncEnabled);
        $this->assertSame(120, $settings->syncIntervalMinutes);
        $this->assertFalse($settings->autoMatch);
    }

    public function testFromArrayWithMissingKeys(): void
    {
        $data = [
            'username' => 'testuser',
        ];

        $settings = AniListSettings::fromArray($data);

        $this->assertNull($settings->accessToken);
        $this->assertSame('testuser', $settings->username);
        $this->assertTrue($settings->syncEnabled);
        $this->assertSame(60, $settings->syncIntervalMinutes);
        $this->assertTrue($settings->autoMatch);
    }

    public function testToArray(): void
    {
        $settings = new AniListSettings(
            accessToken: 'token-abc',
            username: 'animefan',
            syncEnabled: false,
            syncIntervalMinutes: 30,
            autoMatch: false,
        );

        $array = $settings->toArray();

        $this->assertSame('token-abc', $array['access_token']);
        $this->assertSame('animefan', $array['username']);
        $this->assertFalse($array['sync_enabled']);
        $this->assertSame(30, $array['sync_interval_minutes']);
        $this->assertFalse($array['auto_match']);
    }

    public function testToSpaArrayRedactsToken(): void
    {
        $settings = new AniListSettings(
            accessToken: 'secret-token',
            username: 'testuser',
        );

        $spa = $settings->toSpaArray();

        $this->assertArrayNotHasKey('access_token', $spa);
        $this->assertSame('testuser', $spa['username']);
        $this->assertTrue($spa['has_token']);
    }

    public function testHasToken(): void
    {
        $settingsWithToken = new AniListSettings(accessToken: 'token-123');
        $settingsWithoutToken = new AniListSettings();
        $settingsWithEmptyToken = new AniListSettings(accessToken: '');

        $this->assertTrue($settingsWithToken->hasToken());
        $this->assertFalse($settingsWithoutToken->hasToken());
        $this->assertFalse($settingsWithEmptyToken->hasToken());
    }

    public function testIsConfigured(): void
    {
        $fullyConfigured = new AniListSettings(
            accessToken: 'token-123',
            username: 'testuser',
        );

        $noToken = new AniListSettings(
            accessToken: null,
            username: 'testuser',
        );

        $noUsername = new AniListSettings(
            accessToken: 'token-123',
            username: '',
        );

        $this->assertTrue($fullyConfigured->isConfigured());
        $this->assertFalse($noToken->isConfigured());
        $this->assertFalse($noUsername->isConfigured());
    }
}
