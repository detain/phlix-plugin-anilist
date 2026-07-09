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
 * Stub for host-supplied Phlix\Media\Library\ItemRepository class.
 */
if (!class_exists(\Phlix\Media\Library\ItemRepository::class)) {
    class ItemRepositoryStub
    {
        public function findById(string $id): ?array
        {
            return null;
        }

        public function updateMetadata(string $id, array $metadata): void
        {
        }

        public function updateMetadataByExternalId(string $externalIdType, string $externalId, array $metadata): void
        {
        }
    }

    class_alias(ItemRepositoryStub::class, \Phlix\Media\Library\ItemRepository::class);
}
