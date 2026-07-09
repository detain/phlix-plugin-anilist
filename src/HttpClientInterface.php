<?php

/**
 * AniList HTTP Client Interface.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Metadata\AniList;

/**
 * HTTP client interface for AniList API requests.
 *
 * @package Phlix\Plugins\Metadata\AniList
 * @since 0.14.0
 */
interface HttpClientInterface
{
    /**
     * Send a POST request to the AniList GraphQL API.
     *
     * @param string $url Request URL
     * @param array<string, mixed> $data JSON-serializable request body
     * @param array<string, string> $headers HTTP headers
     *
     * @return array<string, mixed> Decoded JSON response
     */
    public function post(string $url, array $data, array $headers = []): array;

    /**
     * Send a GET request to the AniList API.
     *
     * @param string $url Request URL
     * @param array<string, mixed> $params Query parameters
     * @param array<string, string> $headers HTTP headers
     *
     * @return array<string, mixed> Decoded JSON response
     */
    public function get(string $url, array $params = [], array $headers = []): array;
}
