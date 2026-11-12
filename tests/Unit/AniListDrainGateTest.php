<?php

/**
 * Consequence tests for ANL-9: the enrichment drain chain is gated on
 * disable by an explicit generation/flag, not by the accident of a null
 * repository.
 *
 * Laws pinned here (W06L5 "never dials while disabled" posture, extended
 * from the periodic timer to the one-shot drain chain):
 * 1. onDisable() drops the backlog and the lookup memo, clears drainArmed
 *    honestly, and bumps the drain generation;
 * 2. a drain tick armed before onDisable() and firing after it is stale: it
 *    never dials AniList and never rebuilds the API client (the bearer
 *    secret onDisable() dropped) — even with a live repository re-seated;
 * 3. after a re-enable, the stale tick does not run beside the new cycle's
 *    chain (no double rate, no flag theft);
 * 4. a configure() that turns the master enable off while a tick is
 *    outstanding ends the chain at that tick: no dial, no re-arm.
 *
 * The drain timer is observed through the protected addOneShotTimer() seam
 * (the addRepeatingTimer() posture): the spy records each arm and keeps the
 * callback so a test can fire it exactly as the Workerman loop would.
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
use Phlix\Shared\Events\Library\MediaItemAdded;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AniListDrainGateTest extends TestCase
{
    /**
     * Plugin subclass whose one-shot drain timer is a recorder: each arm is
     * appended to $drainArms and the callback is kept for manual firing.
     */
    private function makeDrainSpyPlugin(bool $enabled = true): AniListPlugin
    {
        $plugin = new class extends AniListPlugin {
            /** @var list<callable> */
            public array $drainArms = [];

            protected function addOneShotTimer(int $delaySeconds, callable $callback): bool
            {
                $this->drainArms[] = $callback;

                return true;
            }

            protected function addRepeatingTimer(int $intervalSeconds, callable $callback): ?int
            {
                return null;
            }
        };

        $this->setProp($plugin, 'settings', new AniListSettings(
            accessToken: 'test-token',
            username: 'testuser',
            syncEnabled: true,
        ));
        $this->setProp($plugin, 'enabled', $enabled);
        $this->setProp($plugin, 'logger', new NullLogger());
        $this->setProp($plugin, 'itemRepository', $this->repo());

        return $plugin;
    }

    private function repo(): ItemRepository
    {
        $repo = new ItemRepository();
        foreach (['m1' => 1, 'm2' => 2] as $id => $malId) {
            $repo->rows[$id] = [
                'id' => $id,
                'name' => 'Item ' . $id,
                'type' => 'episode',
                'path' => '/' . $id . '.mkv',
                'metadata' => ['mal_id' => $malId],
            ];
        }

        return $repo;
    }

    /**
     * AniList double counting MAL lookups — a lookup is the dial.
     */
    private function countingApi(): AniListApi
    {
        return new class extends AniListApi {
            public int $lookups = 0;

            public function __construct()
            {
                $http = new class implements HttpClientInterface {
                    public function post(string $url, array $data, array $headers = []): array
                    {
                        throw new \LogicException('unused double');
                    }

                    public function get(string $url, array $params = [], array $headers = []): array
                    {
                        throw new \LogicException('unused double');
                    }
                };
                parent::__construct($http, 'test-token', new NullLogger());
            }

            /**
             * @return array<string, mixed>|null
             */
            public function getMediaByMalId(int $malId): ?array
            {
                $this->lookups++;

                return ['id' => 100 + $malId, 'idMal' => $malId, 'title' => ['romaji' => 'T' . $malId]];
            }
        };
    }

    private function enqueue(AniListPlugin $plugin, string $id): void
    {
        $plugin->onMediaItemAdded(new MediaItemAdded($id, 'lib', '/' . $id . '.mkv', 'episode'));
    }

    private function fire(callable $tick): void
    {
        $tick();
    }

    private function prop(object $obj, string $name): \ReflectionProperty
    {
        for ($class = new \ReflectionClass($obj); $class !== false; $class = $class->getParentClass()) {
            if ($class->hasProperty($name)) {
                return $class->getProperty($name);
            }
        }

        throw new \RuntimeException("Property {$name} not found on " . $obj::class);
    }

    private function setProp(object $obj, string $name, mixed $value): void
    {
        $this->prop($obj, $name)->setValue($obj, $value);
    }

    private function getProp(object $obj, string $name): mixed
    {
        return $this->prop($obj, $name)->getValue($obj);
    }

    public function test_positive_control_an_armed_tick_drains_one_and_rearms(): void
    {
        $plugin = $this->makeDrainSpyPlugin();
        $api = $this->countingApi();
        $this->setProp($plugin, 'api', $api);

        $this->enqueue($plugin, 'm1');
        $this->enqueue($plugin, 'm2');
        $this->assertCount(1, $plugin->drainArms, 'one arm for a burst — drainArmed refuses stacking');

        $this->fire($plugin->drainArms[0]);

        $this->assertSame(1, $api->lookups, 'the live tick must enrich one item');
        $this->assertSame(['m2'], $this->getProp($plugin, 'enrichQueue'));
        $this->assertCount(2, $plugin->drainArms, 'backlog remains, so the tick re-arms');
    }

    public function test_on_disable_ends_the_drain_chain_explicitly(): void
    {
        $plugin = $this->makeDrainSpyPlugin();
        $this->setProp($plugin, 'api', $this->countingApi());
        $this->setProp($plugin, 'lookupMemo', [1 => null]);

        $this->enqueue($plugin, 'm1');
        $this->enqueue($plugin, 'm2');
        $this->assertTrue($this->getProp($plugin, 'drainArmed'));
        $generation = $this->getProp($plugin, 'drainGeneration');

        $plugin->onDisable();

        $this->assertSame([], $this->getProp($plugin, 'enrichQueue'), 'the backlog must not survive disable');
        $this->assertSame([], $this->getProp($plugin, 'lookupMemo'), 'the lookup memo must not survive disable');
        $this->assertFalse($this->getProp($plugin, 'drainArmed'), 'the outstanding tick is stale: the flag says so');
        $this->assertSame($generation + 1, $this->getProp($plugin, 'drainGeneration'));
    }

    public function test_stale_tick_after_disable_never_rebuilds_the_client(): void
    {
        $plugin = $this->makeDrainSpyPlugin();

        $this->enqueue($plugin, 'm1');
        $tick = $plugin->drainArms[0];

        $plugin->onDisable();
        // Re-seat a live repository so the null-repo accident cannot be what
        // stops the chain: the generation gate must do it.
        $this->setProp($plugin, 'itemRepository', $this->repo());
        $this->setProp($plugin, 'enrichQueue', ['m1']);

        $this->fire($tick);

        $this->assertNull(
            $this->getProp($plugin, 'api'),
            'a stale tick must not rebuild the client — onDisable() dropped the bearer secret'
        );
        $this->assertSame(['m1'], $this->getProp($plugin, 'enrichQueue'), 'a stale tick must not pop anything');
        $this->assertCount(1, $plugin->drainArms, 'a stale tick must not re-arm');
    }

    public function test_stale_tick_never_dials_with_a_live_client_and_repository(): void
    {
        $plugin = $this->makeDrainSpyPlugin();
        $this->setProp($plugin, 'api', $this->countingApi());

        $this->enqueue($plugin, 'm1');
        $tick = $plugin->drainArms[0];

        $plugin->onDisable();
        $api = $this->countingApi();
        $repo = $this->repo();
        $this->setProp($plugin, 'api', $api);
        $this->setProp($plugin, 'itemRepository', $repo);
        $this->setProp($plugin, 'enrichQueue', ['m1']);

        $this->fire($tick);

        $this->assertSame(0, $api->lookups, 'a tick armed before disable must never dial AniList');
        $this->assertSame([], $repo->updateCalls);
    }

    public function test_stale_tick_does_not_run_beside_the_next_cycle_chain(): void
    {
        $plugin = $this->makeDrainSpyPlugin();

        $this->enqueue($plugin, 'm1');
        $staleTick = $plugin->drainArms[0];

        $plugin->onDisable();
        // Re-enable: collaborators come back, a new item arms a fresh chain.
        $api = $this->countingApi();
        $this->setProp($plugin, 'api', $api);
        $this->setProp($plugin, 'itemRepository', $this->repo());
        $this->enqueue($plugin, 'm2');
        $this->assertCount(2, $plugin->drainArms, 'the new cycle arms its own tick (the flag was cleared honestly)');

        $this->fire($staleTick);

        $this->assertSame(0, $api->lookups, 'the stale tick must not drain the new cycle\'s backlog');
        $this->assertSame(['m2'], $this->getProp($plugin, 'enrichQueue'));
        $this->assertTrue($this->getProp($plugin, 'drainArmed'), 'the stale tick must not steal the new chain\'s flag');
        $this->assertCount(2, $plugin->drainArms, 'the stale tick must not arm a second concurrent chain');

        $this->fire($plugin->drainArms[1]);

        $this->assertSame(1, $api->lookups, 'the live chain still drains');
        $this->assertSame([], $this->getProp($plugin, 'enrichQueue'));
    }

    public function test_configure_disable_mid_chain_ends_it_at_the_next_tick(): void
    {
        $plugin = $this->makeDrainSpyPlugin();

        $this->enqueue($plugin, 'm1');
        $this->enqueue($plugin, 'm2');
        $tick = $plugin->drainArms[0];

        $plugin->configure([
            'enabled' => false,
            'access_token' => 'test-token',
            'username' => 'testuser',
            'sync_enabled' => true,
        ]);
        // configure() drops the client; seat a counting one so a dial would show.
        $api = $this->countingApi();
        $this->setProp($plugin, 'api', $api);

        $this->fire($tick);

        $this->assertSame(0, $api->lookups, 'a master-disabled plugin must not enrich from its backlog');
        $this->assertSame([], $this->getProp($plugin, 'enrichQueue'), 'the chain ends: backlog dropped');
        $this->assertFalse($this->getProp($plugin, 'drainArmed'));
        $this->assertCount(1, $plugin->drainArms, 'a disabled tick must not re-arm');
    }

    public function test_configure_disable_tick_does_not_rebuild_the_client(): void
    {
        $plugin = $this->makeDrainSpyPlugin();

        $this->enqueue($plugin, 'm1');
        $tick = $plugin->drainArms[0];

        $plugin->configure([
            'enabled' => false,
            'access_token' => 'test-token',
            'username' => 'testuser',
        ]);

        $this->fire($tick);

        $this->assertNull($this->getProp($plugin, 'api'), 'ensureApi() must not run behind the master enable');
    }
}
