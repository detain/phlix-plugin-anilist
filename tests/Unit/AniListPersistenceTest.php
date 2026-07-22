<?php

/**
 * AniList persistence + enrichment consequence tests.
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
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * These assert CONSEQUENCES of the fix, not mere resolvability:
 *  - enrichment persists through the REAL {@see ItemRepository::update()} with a
 *    `metadata_json` gap-fill payload that never clobbers existing fields;
 *  - a write failure is LOGGED at error level (not silently swallowed);
 *  - `MediaItemAdded` enqueues for throttled draining and does NOT call AniList
 *    inline (no per-item scan flood);
 *  - the drain path is what actually performs the enrichment write.
 */
final class AniListPersistenceTest extends TestCase
{
    /**
     * @param array<string, mixed>|null $entry AniList Media entry returned by the API
     */
    private function makePlugin(
        ItemRepository $repo,
        ?array $entry,
        LoggerInterface $logger,
        bool $autoMatch = true,
    ): AniListPlugin {
        $http = new class ($entry) implements HttpClientInterface {
            /** @param array<string, mixed>|null $entry */
            public function __construct(private ?array $entry)
            {
            }

            public function post(string $url, array $data, array $headers = []): array
            {
                return ['data' => ['Media' => $this->entry]];
            }

            public function get(string $url, array $params = [], array $headers = []): array
            {
                return [];
            }
        };

        $api = new AniListApi($http, 'manual-pat', new NullLogger());

        $settings = new AniListSettings(
            accessToken: 'manual-pat',
            username: 'someuser',
            autoMatch: $autoMatch,
        );

        $ref = new \ReflectionClass(AniListPlugin::class);
        /** @var AniListPlugin $plugin */
        $plugin = $ref->newInstanceWithoutConstructor();

        $this->setProp($plugin, 'settings', $settings);
        $this->setProp($plugin, 'enabled', true);
        $this->setProp($plugin, 'itemRepository', $repo);
        $this->setProp($plugin, 'api', $api);
        $this->setProp($plugin, 'logger', $logger);

        return $plugin;
    }

    private function setProp(object $obj, string $name, mixed $value): void
    {
        $prop = new \ReflectionProperty($obj, $name);
        $prop->setAccessible(true);
        $prop->setValue($obj, $value);
    }

    private function getProp(object $obj, string $name): mixed
    {
        $prop = new \ReflectionProperty($obj, $name);
        $prop->setAccessible(true);

        return $prop->getValue($obj);
    }

    private function invoke(object $obj, string $method, mixed ...$args): mixed
    {
        $m = new \ReflectionMethod($obj, $method);
        $m->setAccessible(true);

        return $m->invoke($obj, ...$args);
    }

    public function test_enrichment_persists_through_real_update_with_metadata_json_payload(): void
    {
        $repo = new ItemRepository();
        $repo->rows['m1'] = [
            'id' => 'm1',
            'name' => 'Cowboy Bebop',
            'type' => 'episode',
            'path' => '/x.mkv',
            'metadata' => [
                'mal_id' => 1,
                'title_romaji' => 'Existing Title',
            ],
        ];

        $entry = [
            'id' => 99,
            'idMal' => 1,
            'title' => ['romaji' => 'Overwritten Romaji', 'english' => 'Cowboy Bebop'],
            'averageScore' => 85,
            'genres' => ['Action', 'Sci-Fi'],
        ];

        $plugin = $this->makePlugin($repo, $entry, new NullLogger());
        $this->invoke($plugin, 'enrichMediaItem', 'm1');

        $this->assertCount(1, $repo->updateCalls, 'update() must be called exactly once');
        $call = $repo->updateCalls[0];
        $this->assertSame('m1', $call['id']);
        $this->assertArrayHasKey('metadata_json', $call['data'], 'payload must target the metadata_json column');

        $written = $call['data']['metadata_json'];
        $this->assertIsArray($written);

        // Gap-fill: new fields added.
        $this->assertSame(99, $written['anilist_id']);
        $this->assertSame(85, $written['average_score']);
        $this->assertSame(['Action', 'Sci-Fi'], $written['genres']);

        // Merge: existing non-empty fields are NOT clobbered.
        $this->assertSame('Existing Title', $written['title_romaji']);
        $this->assertSame(1, $written['mal_id']);
    }

    public function test_write_failure_is_logged_at_error_level_not_swallowed(): void
    {
        $repo = new ItemRepository();
        $repo->rows['m1'] = [
            'id' => 'm1',
            'name' => 'X',
            'type' => 'episode',
            'path' => '/x.mkv',
            'metadata' => ['mal_id' => 1],
        ];
        $repo->failIds = ['m1']; // update() will throw

        $entry = ['id' => 99, 'idMal' => 1, 'title' => ['romaji' => 'X'], 'averageScore' => 70];

        $logger = new RecordingAniListLogger();
        $plugin = $this->makePlugin($repo, $entry, $logger);

        $this->invoke($plugin, 'enrichMediaItem', 'm1');

        $this->assertTrue(
            $logger->hasRecord('error', 'failed to persist media item metadata'),
            'a write failure must be logged at error level'
        );
    }

    public function test_media_item_added_enqueues_and_does_not_enrich_inline(): void
    {
        $repo = new ItemRepository();
        $repo->rows['m1'] = [
            'id' => 'm1',
            'name' => 'X',
            'type' => 'episode',
            'path' => '/x.mkv',
            'metadata' => ['mal_id' => 1],
        ];

        $entry = ['id' => 99, 'idMal' => 1, 'title' => ['romaji' => 'X'], 'averageScore' => 70];
        $plugin = $this->makePlugin($repo, $entry, new NullLogger());

        $plugin->onMediaItemAdded(new MediaItemAdded('m1', 'lib', '/x.mkv', 'episode'));

        $queue = $this->getProp($plugin, 'enrichQueue');
        $this->assertSame(['m1'], $queue, 'the item must be queued');
        $this->assertCount(0, $repo->updateCalls, 'the handler must NOT enrich inline (no scan flood)');
    }

    public function test_auto_match_disabled_does_not_enqueue(): void
    {
        $repo = new ItemRepository();
        $plugin = $this->makePlugin($repo, null, new NullLogger(), autoMatch: false);

        $plugin->onMediaItemAdded(new MediaItemAdded('m1', 'lib', '/x.mkv', 'episode'));

        $this->assertSame([], $this->getProp($plugin, 'enrichQueue'));
    }

    public function test_drain_performs_the_enrichment_write(): void
    {
        $repo = new ItemRepository();
        $repo->rows['m1'] = [
            'id' => 'm1',
            'name' => 'X',
            'type' => 'episode',
            'path' => '/x.mkv',
            'metadata' => ['mal_id' => 1],
        ];

        $entry = ['id' => 99, 'idMal' => 1, 'title' => ['romaji' => 'X'], 'averageScore' => 70];
        $plugin = $this->makePlugin($repo, $entry, new NullLogger());

        $this->setProp($plugin, 'enrichQueue', ['m1']);
        $this->invoke($plugin, 'drainOne');

        $this->assertSame([], $this->getProp($plugin, 'enrichQueue'), 'the item must be popped');
        $this->assertCount(1, $repo->updateCalls, 'draining must persist the enrichment');
        $this->assertSame(99, $repo->updateCalls[0]['data']['metadata_json']['anilist_id']);
    }
}

/**
 * Minimal PSR-3 recording logger.
 */
final class RecordingAniListLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    public function hasRecord(string $level, string $needle): bool
    {
        foreach ($this->records as $record) {
            if ($record['level'] === $level && str_contains($record['message'], $needle)) {
                return true;
            }
        }

        return false;
    }
}
