<?php

/**
 * AniList Settings.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Metadata\AniList;

/**
 * Per-user settings for the AniList metadata provider plugin.
 *
 * Stores API tokens, sync preferences, and user identity.
 * Settings are serialized to JSON and stored in the plugins.settings_json
 * column, loaded via Plugin::configure() on enable.
 *
 * @package Phlix\Plugins\Metadata\AniList
 * @since 0.14.0
 */
final class AniListSettings
{
    /**
     * @param string|null $accessToken AniList API access token (null when not authenticated)
     * @param string $username AniList username for watchlist sync
     * @param bool $syncEnabled Whether watchlist sync is enabled
     * @param int $syncIntervalMinutes How often to run sync (in minutes)
     * @param bool $autoMatch Whether to auto-match local media to AniList entries
     */
    public function __construct(
        public readonly ?string $accessToken = null,
        public readonly string $username = '',
        public readonly bool $syncEnabled = true,
        public readonly int $syncIntervalMinutes = 60,
        public readonly bool $autoMatch = true,
    ) {
    }

    /**
     * Create settings from an array (as loaded from DB settings JSON).
     *
     * @param array<string, mixed> $data Key-value array from settings_json
     *
     * @return self
     *
     * @since 0.14.0
     */
    public static function fromArray(array $data): self
    {
        return new self(
            accessToken: is_string($data['access_token'] ?? null) ? $data['access_token'] : null,
            username: is_string($data['username'] ?? null) ? $data['username'] : '',
            syncEnabled: is_bool($data['sync_enabled'] ?? null) ? $data['sync_enabled'] : true,
            syncIntervalMinutes: is_int($data['sync_interval_minutes'] ?? null) ? $data['sync_interval_minutes'] : 60,
            autoMatch: is_bool($data['auto_match'] ?? null) ? $data['auto_match'] : true,
        );
    }

    /**
     * Convert settings to an array representation.
     *
     * @return array<string, mixed>
     *
     * @since 0.14.0
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'username' => $this->username,
            'sync_enabled' => $this->syncEnabled,
            'sync_interval_minutes' => $this->syncIntervalMinutes,
            'auto_match' => $this->autoMatch,
        ];
    }

    /**
     * Convert settings to a REDACTED projection safe to return to the admin SPA.
     *
     * The raw API token is NEVER included; instead a `has_token` boolean
     * signals whether the account is connected.
     *
     * @return array<string, mixed>
     *
     * @since 0.14.0
     */
    public function toSpaArray(): array
    {
        return [
            'username' => $this->username,
            'sync_enabled' => $this->syncEnabled,
            'sync_interval_minutes' => $this->syncIntervalMinutes,
            'auto_match' => $this->autoMatch,
            'has_token' => $this->hasToken(),
        ];
    }

    /**
     * Whether an API token is present.
     *
     * @return bool True when an access token is present
     *
     * @since 0.14.0
     */
    public function hasToken(): bool
    {
        return $this->accessToken !== null && $this->accessToken !== '';
    }

    /**
     * Whether the plugin is fully configured and ready to sync.
     *
     * @return bool True when hasToken() and username is non-empty
     *
     * @since 0.14.0
     */
    public function isConfigured(): bool
    {
        return $this->hasToken() && $this->username !== '';
    }
}
