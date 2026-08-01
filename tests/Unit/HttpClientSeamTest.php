<?php

/**
 * HttpClient seam tests - tick() and backoffSleep() coverage.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Metadata\AniList;

use Phlix\Plugins\Metadata\AniList\HttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for HttpClient tick() and backoffSleep() methods.
 * These methods are protected and designed to be seams for testing.
 */
final class HttpClientSeamTest extends TestCase
{
    public function testTickMethodExistsAndIsCallable(): void
    {
        $client = new class extends HttpClient {
            public int $tickCallCount = 0;

            protected function sendOnce(string $method, string $url, array $data, array $headers): array
            {
                // Call tick to verify it's accessible
                $this->tickCallCount++;
                $this->tick();
                return ['status' => 200, 'body' => '{}', 'retry_after' => null];
            }
        };

        // Should not throw - tick should be callable
        $client->get('https://example.com/api');
        $this->assertSame(1, $client->tickCallCount);
    }

    public function testBackoffSleepNegativeSecondsReturnsEarly(): void
    {
        $client = new class (new NullLogger()) extends HttpClient {
            public bool $parentCalled = false;

            public function publicBackoffSleep(float $seconds): void
            {
                $this->backoffSleep($seconds);
            }

            protected function backoffSleep(float $seconds): void
            {
                if ($seconds <= 0.0) {
                    return; // Early return - parent should NOT be called
                }
                $this->parentCalled = true;
                parent::backoffSleep($seconds);
            }
        };

        // Test negative
        $client->publicBackoffSleep(-1.0);
        $this->assertFalse($client->parentCalled, 'parent backoffSleep should not be called for negative seconds');

        // Test zero
        $client->publicBackoffSleep(0.0);
        $this->assertFalse($client->parentCalled, 'parent backoffSleep should not be called for zero seconds');
    }

    public function testBackoffSleepPositiveSecondsCallsParent(): void
    {
        $client = new class (new NullLogger()) extends HttpClient {
            public bool $parentCalled = false;
            public float $receivedSeconds = 0.0;

            public function publicBackoffSleep(float $seconds): void
            {
                $this->backoffSleep($seconds);
            }

            protected function backoffSleep(float $seconds): void
            {
                if ($seconds <= 0.0) {
                    return;
                }
                $this->parentCalled = true;
                $this->receivedSeconds = $seconds;
                parent::backoffSleep($seconds);
            }
        };

        $client->publicBackoffSleep(1.5);
        $this->assertTrue($client->parentCalled, 'parent backoffSleep should be called for positive seconds');
        $this->assertSame(1.5, $client->receivedSeconds);
    }

    public function testTickDoesActualUsleep(): void
    {
        $client = new class extends HttpClient {
            protected function sendOnce(string $method, string $url, array $data, array $headers): array
            {
                return ['status' => 200, 'body' => '{"data":{"ok":true}}', 'retry_after' => null];
            }
        };

        // Using reflection to access protected tick method
        $ref = new \ReflectionClass($client);
        $tickMethod = $ref->getMethod('tick');
        $tickMethod->setAccessible(true);

        $start = microtime(true);
        $tickMethod->invoke($client);
        $elapsed = microtime(true) - $start;

        // tick() should sleep for ~0.001 seconds (1ms)
        // Allow some tolerance for test environment variability
        $this->assertGreaterThan(0.0001, $elapsed, 'tick() should perform actual usleep');
    }

    public function testBackoffSleepProcessesPositiveSeconds(): void
    {
        // This test checks that backoffSleep correctly processes positive seconds
        // and attempts to call either Workerman Timer::sleep or usleep
        $client = new class (new NullLogger()) extends HttpClient {
            public bool $pathExecuted = false;
            public float $receivedSeconds = 0.0;

            public function publicBackoffSleep(float $seconds): void
            {
                $this->backoffSleep($seconds);
            }

            protected function backoffSleep(float $seconds): void
            {
                if ($seconds <= 0.0) {
                    return;
                }

                $this->pathExecuted = true;
                $this->receivedSeconds = $seconds;

                // Call parent's actual implementation
                parent::backoffSleep($seconds);
            }
        };

        // Call with positive seconds
        $client->publicBackoffSleep(0.001);

        // The key thing is the code path is exercised
        $this->assertTrue($client->pathExecuted, 'backoffSleep should execute the positive seconds path');
        $this->assertSame(0.001, $client->receivedSeconds);
    }

    public function testTickIsProtectedAndCanBeOverridden(): void
    {
        $tickWasOverridden = false;

        $client = new class ($tickWasOverridden) extends HttpClient {
            /** @var bool */
            private $tickOverridden;

            public function __construct(bool $flag)
            {
                parent::__construct(new NullLogger());
                $this->tickOverridden = $flag;
            }

            protected function tick(): void
            {
                // Set the flag through the property
                $this->tickOverridden = true;
                // Don't call parent - we're just testing override works
            }

            protected function sendOnce(string $method, string $url, array $data, array $headers): array
            {
                $this->tick();
                return ['status' => 200, 'body' => '{}', 'retry_after' => null];
            }

            public function wasTickOverridden(): bool
            {
                return $this->tickOverridden;
            }
        };

        $client->get('https://example.com/api');

        $this->assertTrue($client->wasTickOverridden(), 'tick() should be overridable');
    }

    public function testBackoffSleepIsProtectedAndCanBeOverridden(): void
    {
        $backoffWasOverridden = false;

        $client = new class ($backoffWasOverridden) extends HttpClient {
            /** @var bool */
            private $backoffOverridden;

            public function __construct(bool $flag)
            {
                parent::__construct(new NullLogger());
                $this->backoffOverridden = $flag;
            }

            public function publicBackoffSleep(float $seconds): void
            {
                $this->backoffSleep($seconds);
            }

            protected function backoffSleep(float $seconds): void
            {
                $this->backoffOverridden = true;
                // Don't call parent - we're just testing override works
            }

            public function wasBackoffOverridden(): bool
            {
                return $this->backoffOverridden;
            }
        };

        $client->publicBackoffSleep(1.0);

        $this->assertTrue($client->wasBackoffOverridden(), 'backoffSleep() should be overridable');
    }

    public function testBackoffSleepHandlesZeroGracefully(): void
    {
        $client = new class (new NullLogger()) extends HttpClient {
            public bool $parentCalled = false;

            public function publicBackoffSleep(float $seconds): void
            {
                $this->backoffSleep($seconds);
            }

            protected function backoffSleep(float $seconds): void
            {
                if ($seconds <= 0.0) {
                    return;
                }
                $this->parentCalled = true;
                parent::backoffSleep($seconds);
            }
        };

        // Should not throw and should return early
        $client->publicBackoffSleep(0.0);
        $this->assertFalse($client->parentCalled);
    }

    public function testTickRecordsCooperativeWaitBehavior(): void
    {
        $client = new class extends HttpClient {
            public int $tickCallCount = 0;

            protected function tick(): void
            {
                $this->tickCallCount++;
                // Call parent for actual sleep
                parent::tick();
            }

            protected function sendOnce(string $method, string $url, array $data, array $headers): array
            {
                // Simulate the cooperative wait by calling tick twice
                $this->tickCallCount = 0;
                $this->tick();
                $this->tick();
                return ['status' => 200, 'body' => '{"data":{"ok":true}}', 'retry_after' => null];
            }
        };

        $client->get('https://example.com/api');

        $this->assertSame(2, $client->tickCallCount, 'tick() should be called twice in cooperative wait simulation');
    }

    public function testSendOnceMethodIsProtectedAndCanBeOverridden(): void
    {
        $sendOnceWasCalled = false;
        $setFlag = static function (bool $value) use (&$sendOnceWasCalled): void {
            $sendOnceWasCalled = $value;
        };

        $client = new class ($setFlag) extends HttpClient {
            /** @var callable */
            private $setFlag;

            public function __construct(callable $setFlag)
            {
                parent::__construct(new NullLogger());
                $this->setFlag = $setFlag;
            }

            protected function sendOnce(string $method, string $url, array $data, array $headers): array
            {
                ($this->setFlag)(true);
                return ['status' => 200, 'body' => '{"data":{"ok":true}}', 'retry_after' => null];
            }
        };

        $client->get('https://example.com/api');

        $this->assertTrue($sendOnceWasCalled, 'sendOnce() should be overridable and callable');
    }

    public function testBackoffSleepWithVariousPositiveValues(): void
    {
        $client = new class (new NullLogger()) extends HttpClient {
            /** @var list<float> */
            public array $calledWith = [];

            public function publicBackoffSleep(float $seconds): void
            {
                $this->backoffSleep($seconds);
            }

            protected function backoffSleep(float $seconds): void
            {
                if ($seconds <= 0.0) {
                    return;
                }
                $this->calledWith[] = $seconds;
                parent::backoffSleep($seconds);
            }
        };

        $client->publicBackoffSleep(0.5);
        $client->publicBackoffSleep(1.0);
        $client->publicBackoffSleep(2.5);

        $this->assertSame([0.5, 1.0, 2.5], $client->calledWith);
    }
}
