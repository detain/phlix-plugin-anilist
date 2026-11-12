<?php

/**
 * ANL-1: sendOnce() must fail fast (typed exception) when the cooperative
 * wait cannot yield, instead of blocking the worker in usleep().
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Metadata\AniList;

use Phlix\Plugins\Metadata\AniList\AniListApiException;
use Phlix\Plugins\Metadata\AniList\HttpClient;
use PHPUnit\Framework\TestCase;

final class AniListHttpClientRuntimeGuardTest extends TestCase
{
    public function testSendOnceThrowsTypedExceptionOutsideCoroutineRuntime(): void
    {
        $client = new class extends HttpClient {
            public int $ticks = 0;

            protected function coroutineRuntimeAvailable(): bool
            {
                return false;
            }

            protected function tick(): void
            {
                $this->ticks++;
            }

            public function callSendOnce(): array
            {
                return $this->sendOnce('POST', 'https://graphql.anilist.co', [], []);
            }
        };

        try {
            $client->callSendOnce();
            self::fail('expected AniListApiException');
        } catch (AniListApiException $e) {
            self::assertStringContainsString('Swoole coroutine runtime', $e->getMessage());
        }

        self::assertSame(0, $client->ticks, 'must fail before any blocking wait');
    }

    public function testProbeIsFalseWithoutSwooleCoroutine(): void
    {
        $client = new class extends HttpClient {
            public function probe(): bool
            {
                return $this->coroutineRuntimeAvailable();
            }
        };

        self::assertFalse($client->probe());
    }
}
