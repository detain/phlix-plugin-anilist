<?php

/**
 * ANL-S2: single-syncer flock election per tick + page-walk pacing.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Metadata\AniList;

use Phlix\Media\Library\ItemRepository;
use Phlix\Plugins\Metadata\AniList\AniListApi;
use Phlix\Plugins\Metadata\AniList\AniListPlugin;
use Phlix\Plugins\Metadata\AniList\AniListSettings;
use Phlix\Plugins\Metadata\AniList\HttpClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AniListSyncElectionPacingTest extends TestCase
{
    private function api(int $fullPages): AniListApi
    {
        return new class($fullPages) extends AniListApi {
            public int $listCalls = 0;

            public function __construct(private int $fullPages)
            {
                parent::__construct(new class implements HttpClientInterface {
                    public function post(string $url, array $data, array $headers = []): array
                    {
                        throw new \LogicException('unused');
                    }

                    public function get(string $url, array $params = [], array $headers = []): array
                    {
                        throw new \LogicException('unused');
                    }
                }, 'test-token', new NullLogger());
            }

            public function getUserAnimeList(string $username, ?string $status = null, int $page = 1, int $perPage = 50): array
            {
                $this->listCalls++;
                if ($status !== 'CURRENT' || $page > $this->fullPages) {
                    return [];
                }

                return array_fill(0, $perPage, ['media' => ['id' => 1]]);
            }
        };
    }

    private function plugin(AniListApi $api): AniListPlugin
    {
        $plugin = new class extends AniListPlugin {
            /** @var list<float> */
            public array $sleeps = [];

            protected function pageSleep(float $seconds): void
            {
                $this->sleeps[] = $seconds;
            }
        };
        $set = function (string $n, mixed $v) use ($plugin): void {
            $r = new \ReflectionProperty(AniListPlugin::class, $n);
            $r->setValue($plugin, $v);
        };
        $set('settings', new AniListSettings(accessToken: 'test-token', username: 'elect-user', syncEnabled: true));
        $set('enabled', true);
        $set('api', $api);
        $set('logger', new NullLogger());
        $set('itemRepository', new ItemRepository());

        return $plugin;
    }

    private function lockPath(): string
    {
        return sys_get_temp_dir() . '/phlix-anilist-sync-' . hash('sha256', 'elect-user') . '.lock';
    }

    public function test_tick_is_skipped_when_another_worker_holds_the_election(): void
    {
        $api = $this->api(0);
        $plugin = $this->plugin($api);

        $other = fopen($this->lockPath(), 'c');
        $this->assertNotFalse($other);
        $this->assertTrue(flock($other, LOCK_EX | LOCK_NB));

        try {
            $m = new \ReflectionMethod($plugin, 'runScheduledSync');
            $m->invoke($plugin);
            $this->assertSame(0, $api->listCalls, 'loser of the election must not walk the list');
        } finally {
            flock($other, LOCK_UN);
            fclose($other);
        }

        $m->invoke($plugin);
        $this->assertSame(5, $api->listCalls, 'winner walks all five statuses');
    }

    public function test_lock_is_released_after_the_tick(): void
    {
        $plugin = $this->plugin($this->api(0));
        (new \ReflectionMethod($plugin, 'runScheduledSync'))->invoke($plugin);

        $h = fopen($this->lockPath(), 'c');
        $this->assertNotFalse($h);
        $this->assertTrue(flock($h, LOCK_EX | LOCK_NB), 'election lock must be free after the tick');
        fclose($h);
    }

    public function test_pages_are_paced_between_fetches(): void
    {
        $api = $this->api(3);
        $plugin = $this->plugin($api);

        (new \ReflectionMethod($plugin, 'syncStatusList'))->invoke($plugin, 'elect-user', 'CURRENT');

        // pages 1-3 full, page 4 empty: 4 fetches, 3 pauses, none before the first.
        $this->assertSame(4, $api->listCalls);
        $this->assertSame([0.7, 0.7, 0.7], $plugin->sleeps);
    }
}
