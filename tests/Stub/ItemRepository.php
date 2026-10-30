<?php

/**
 * Analysis-only stub for the host-supplied library ItemRepository.
 *
 * PHPStan loads this via phpstan.neon bootstrapFiles so the plugin's src/
 * can be analysed at level 9: the real class lives in phlix-server
 * (src/Media/Library/ItemRepository.php) and is only present at runtime
 * inside a host worker, never in this plugin's vendor tree. Only the
 * contract surface this plugin consumes is mirrored; `update()` takes the
 * host's column => value map (metadata travels as the decoded blob under
 * `metadata_json`), and `findById()`/`findByMetadataField()` return the
 * host's hydrated row (JSON blob decoded into the `metadata` key).
 * findByMetadataField() exists on host master (bfd4af98) but not in the
 * v1.2.x release tags — the plugin therefore calls it behind a runtime
 * method_exists() guard, which this mirror intentionally does NOT model
 * (analysis sees the modern host; the guard is a deploy-matrix concern).
 *
 * This file is deliberately NOT composer-autoloaded (tests/Stub/ has no
 * psr-4 mapping) so it can never collide with the host class or with the
 * runtime stubs in tests/bootstrap.php.
 *
 * @see https://github.com/detain/phlix-server src/Media/Library/ItemRepository.php
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

class ItemRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        throw new \LogicException('PHPStan analysis-only stub; never called at runtime.');
    }

    /**
     * @param mixed $value
     *
     * @return array<string, mixed>|null
     */
    public function findByMetadataField(string $field, $value): ?array
    {
        throw new \LogicException('PHPStan analysis-only stub; never called at runtime.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(string $id, array $data): void
    {
        throw new \LogicException('PHPStan analysis-only stub; never called at runtime.');
    }
}
