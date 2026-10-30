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
        private readonly ?string $accessToken = null,
        public readonly string $username = '',
        public readonly bool $syncEnabled = true,
        public readonly int $syncIntervalMinutes = 60,
        public readonly bool $autoMatch = true,
    ) {
    }

    /**
     * The raw AniList access token, or null when not connected.
     *
     * CUSTODY (M-ANILIST-11): this is a bearer secret. The property is private,
     * so the serializers that respect visibility — `json_encode()`, `(array)`
     * casts — cannot carry it; `print_r`/`var_export` dump ALL properties by
     * language design and are developer-only tools (nothing in this plugin
     * pipes them to logs or a wire). {@see toArray()} omits the token unless
     * the caller explicitly
     * asks for the persisted shape. Only {@see toSpaArray()} may reach a wire
     * response, and it never carries the token (existence is signaled via
     * `has_token`). Prefer {@see hasToken()} for existence checks; read the
     * token itself only where the Authorization header is built.
     *
     * @return string|null The access token, or null when unset
     */
    public function accessToken(): ?string
    {
        return $this->accessToken;
    }

    /**
     * Create settings from an array (as loaded from DB settings JSON).
     *
     * @param array<string, mixed> $data Key-value array from settings_json
     *
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            accessToken: is_string($data['access_token'] ?? null) === true ? $data['access_token'] : null,
            username: is_string($data['username'] ?? null) === true ? $data['username'] : '',
            syncEnabled: is_bool($data['sync_enabled'] ?? null) === true ? $data['sync_enabled'] : true,
            syncIntervalMinutes: is_int($data['sync_interval_minutes'] ?? null) === true
                ? $data['sync_interval_minutes']
                : 60,
            autoMatch: is_bool($data['auto_match'] ?? null) === true ? $data['auto_match'] : true,
        );
    }

    /**
     * Convert settings to an array representation.
     *
     * The bearer token is OMITTED by default (M-ANILIST-11): this general
     * shape is what callers log, diff and hand to generic serializers, and a
     * secret must not ride along unasked. Only the host settings persistence
     * round-trip needs the full shape — it passes `$includeSecret: true`.
     * Wire/UI projection remains {@see toSpaArray()}.
     *
     * @param bool $includeSecret Include the raw access_token (persistence only)
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $includeSecret = false): array
    {
        $data = [
            'username' => $this->username,
            'sync_enabled' => $this->syncEnabled,
            'sync_interval_minutes' => $this->syncIntervalMinutes,
            'auto_match' => $this->autoMatch,
        ];

        if ($includeSecret === true) {
            $data['access_token'] = $this->accessToken;
        }

        return $data;
    }

    /**
     * Convert settings to a REDACTED projection safe to return to the admin SPA.
     *
     * The raw API token is NEVER included; instead a `has_token` boolean
     * signals whether the account is connected.
     *
     * @return array<string, mixed>
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
     */
    public function hasToken(): bool
    {
        return $this->accessToken !== null && $this->accessToken !== '';
    }

    /**
     * Whether the plugin is fully configured and ready to sync.
     *
     * @return bool True when hasToken() and username is non-empty
     */
    public function isConfigured(): bool
    {
        return $this->hasToken() && $this->username !== '';
    }
}
