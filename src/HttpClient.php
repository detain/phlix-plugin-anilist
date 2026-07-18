<?php

/**
 * AniList HTTP Client.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Metadata\AniList;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Default HTTP client implementation for AniList API requests using cURL.
 *
 * @package Phlix\Plugins\Metadata\AniList
 * @since 0.14.0
 */
class HttpClient implements HttpClientInterface
{
    private readonly LoggerInterface $logger;

    /**
     * @param LoggerInterface|null $logger Optional PSR-3 logger
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * {@inheritdoc}
     */
    public function post(string $url, array $data, array $headers = []): array
    {
        return $this->request('POST', $url, $data, $headers);
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $url, array $params = [], array $headers = []): array
    {
        if ($params !== []) {
            $url .= '?' . http_build_query($params);
        }

        return $this->request('GET', $url, [], $headers);
    }

    /**
     * Execute an HTTP request.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param array<string, mixed> $data Request body (for POST/PUT)
     * @param array<string, string> $headers HTTP headers
     *
     * @return array<string, mixed> Decoded JSON response
     *
     * @throws AniListApiException On request failure
     */
    private function request(string $method, string $url, array $data, array $headers): array
    {
        $ch = curl_init();

        if ($ch === false) {
            throw new AniListApiException('Failed to initialize cURL');
        }

        $headerList = [];
        foreach ($headers as $name => $value) {
            $headerList[] = $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headerList,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($response === false || $error !== '') {
            throw new AniListApiException('cURL error: ' . $error);
        }

        if ($httpCode >= 400) {
            throw new AniListApiException('AniList API returned HTTP ' . $httpCode . ': ' . $response);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new AniListApiException('Invalid JSON response from AniList API');
        }

        $this->logger->debug('AniList API response', [
            'method' => $method,
            'url' => $url,
            'http_code' => $httpCode,
        ]);

        return $decoded;
    }
}
