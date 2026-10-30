<?php

/**
 * Analysis-only stub for the host-supplied Workerman HTTP client.
 *
 * PHPStan loads this via phpstan.neon bootstrapFiles so the plugin's src/
 * can be analysed at level 9: the vendor ships with the host worker runtime
 * (phlix-server), never in this plugin's vendor tree. The mirror follows the
 * host vendor workerman/http-client v3.1.3 surface used by this plugin
 * (src/Client.php: __construct() :59, request() :74) signature-for-signature
 * — request() returns mixed (a Promise under coroutines, or the response for
 * the callback form this plugin drives via the success/error options).
 *
 * This file is deliberately NOT composer-autoloaded (tests/Stub/ has no
 * psr-4 mapping); it exists only for the PHPStan process.
 *
 * @see https://github.com/workerman-http/http-client src/Client.php
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Workerman\Http;

class Client
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        throw new \LogicException('PHPStan analysis-only stub; never called at runtime.');
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $url, array $options = []): mixed
    {
        throw new \LogicException('PHPStan analysis-only stub; never called at runtime.');
    }
}
