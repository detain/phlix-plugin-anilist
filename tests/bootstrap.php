<?php

/**
 * bootstrap.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

// Load the composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Stub for host-supplied Phlix\Media\Library\MediaItem class.
 *
 * Mirrors the pattern used in AniListApiTest for MediaItemStub.
 */
if (!class_exists(\Phlix\Media\Library\MediaItem::class)) {
    final class MediaItemStubForAniList
    {
        public function __construct(
            public string $id,
            public string $name,
            public string $type,
            public string $path,
            public array $metadata = [],
        ) {
        }

        public static function fromRow(array $row): self
        {
            $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];

            return new self(
                id: is_string($row['id'] ?? null) ? $row['id'] : '',
                name: is_string($row['name'] ?? null) ? $row['name'] : '',
                type: is_string($row['type'] ?? null) ? $row['type'] : 'movie',
                path: is_string($row['path'] ?? null) ? $row['path'] : '',
                metadata: $metadata,
            );
        }
    }

    class_alias(MediaItemStubForAniList::class, \Phlix\Media\Library\MediaItem::class);
}

/**
 * Stub for host-supplied Phlix\Shared\Events\Playback\PlaybackStopped event.
 */
if (!class_exists(\Phlix\Shared\Events\Playback\PlaybackStopped::class)) {
    final class PlaybackStoppedStub
    {
        public function __construct(
            public string $mediaItemId,
            public bool $reachedEnd,
            public int $position,
        ) {
        }
    }

    class_alias(PlaybackStoppedStub::class, \Phlix\Shared\Events\Playback\PlaybackStopped::class);
}

/**
 * Recording spy for the host-supplied Phlix\Media\Library\ItemRepository.
 *
 * Mirrors the REAL host surface used by the plugin: findById() (returns a
 * hydrated row whose `metadata` key holds the decoded blob),
 * findByMetadataField() (row lookup by a metadata key — host master
 * bfd4af98; released v1.2.x hosts LACK it, which is why the plugin calls it
 * behind a runtime method_exists() probe), and update(string $id, array
 * $data) — the actual persistence method. It deliberately does NOT expose
 * the non-existent updateMetadata() / updateMetadataByExternalId() so that
 * reintroducing those defects fails.
 */
if (!class_exists(\Phlix\Media\Library\ItemRepository::class)) {
    class ItemRepositoryStub
    {
        /** @var array<string, array<string, mixed>> id => hydrated row */
        public array $rows = [];

        /** @var list<array{id: string, data: array<string, mixed>}> */
        public array $updateCalls = [];

        /** @var list<array{field: string, value: mixed}> */
        public array $byMetadataCalls = [];

        /** @var list<string> IDs whose update() should throw (simulated fault). */
        public array $failIds = [];

        public function findById(string $id): ?array
        {
            return $this->rows[$id] ?? null;
        }

        /**
         * @param mixed $value
         *
         * @return array<string, mixed>|null
         */
        public function findByMetadataField(string $field, $value): ?array
        {
            $this->byMetadataCalls[] = ['field' => $field, 'value' => $value];

            foreach ($this->rows as $row) {
                $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
                if (array_key_exists($field, $metadata) && $metadata[$field] === $value) {
                    return $row;
                }
            }

            return null;
        }

        /**
         * @param array<string, mixed> $data
         */
        public function update(string $id, array $data): void
        {
            if (in_array($id, $this->failIds, true)) {
                throw new \RuntimeException('simulated DB failure for ' . $id);
            }

            $this->updateCalls[] = ['id' => $id, 'data' => $data];
        }
    }

    class_alias(ItemRepositoryStub::class, \Phlix\Media\Library\ItemRepository::class);
}
