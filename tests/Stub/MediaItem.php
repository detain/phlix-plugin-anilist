<?php

/**
 * Analysis-only stub for the host-supplied MediaItem value object.
 *
 * PHPStan loads this via phpstan.neon bootstrapFiles so the plugin's src/
 * can be analysed at level 9: the real class lives in phlix-server
 * (src/Media/Library/MediaItem.php) and is only present at runtime inside a
 * host worker, never in this plugin's vendor tree. The mirror follows the
 * REAL final-class shape with promoted readonly props.
 *
 * This file is deliberately NOT composer-autoloaded (tests/Stub/ has no
 * psr-4 mapping) so it can never collide with the host class or with the
 * runtime stubs in tests/bootstrap.php. Bodies throw because the static
 * signature is the only thing PHPStan needs; this is not a runtime fake.
 *
 * @see https://github.com/detain/phlix-server src/Media/Library/MediaItem.php
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

final class MediaItem
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $id = '',
        public readonly string $name = '',
        public readonly string $type = '',
        public readonly string $path = '',
        public readonly array $metadata = [],
    ) {
        throw new \LogicException('PHPStan analysis-only stub; never instantiated at runtime.');
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        throw new \LogicException('PHPStan analysis-only stub; never called at runtime.');
    }
}
