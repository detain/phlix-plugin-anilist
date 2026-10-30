<?php

/**
 * Analysis-only stub for the host-supplied Workerman Timer.
 *
 * PHPStan loads this via phpstan.neon bootstrapFiles so the plugin's src/
 * can be analysed at level 9: workerman ships with the host worker runtime
 * (phlix-server), never in this plugin's vendor tree. The mirror follows the
 * host vendor v5.2.3 surface used by this plugin
 * (src/Timer.php: add() :142, sleep() :183) signature-for-signature.
 *
 * This file is deliberately NOT composer-autoloaded (tests/Stub/ has no
 * psr-4 mapping); it exists only for the PHPStan process.
 *
 * @see https://github.com/walkor/http-client → workerman/workerman src/Timer.php
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Workerman;

class Timer
{
    /**
     * @param array<mixed>|null $args
     */
    public static function add(float $timeInterval, callable $func, ?array $args = [], bool $persistent = true): int
    {
        throw new \LogicException('PHPStan analysis-only stub; never called at runtime.');
    }

    public static function sleep(float $delay): void
    {
        throw new \LogicException('PHPStan analysis-only stub; never called at runtime.');
    }
}
