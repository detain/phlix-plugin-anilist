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

// Load Workerman MySQL Connection stub for testing
require_once __DIR__ . '/stubs/Workerman/MySQL/Connection.php';

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
 * Recording spy for the host-supplied Phlix\Media\Library\ItemRepository.
 *
 * Mirrors the REAL host surface used by the plugin: findById() (returns a
 * hydrated row whose `metadata` key holds the decoded blob) and
 * update(string $id, array $data) — the actual persistence method. It
 * deliberately does NOT expose the non-existent updateMetadata() /
 * updateMetadataByExternalId() so that reintroducing those defects fails.
 */
if (!class_exists(\Phlix\Media\Library\ItemRepository::class)) {
    class ItemRepositoryStub
    {
        /** @var array<string, array<string, mixed>> id => hydrated row */
        public array $rows = [];

        /** @var list<array{id: string, data: array<string, mixed>}> */
        public array $updateCalls = [];

        /** @var list<string> IDs whose update() should throw (simulated fault). */
        public array $failIds = [];

        public function findById(string $id): ?array
        {
            return $this->rows[$id] ?? null;
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
