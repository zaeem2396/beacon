<?php

declare(strict_types=1);

namespace Beacon\Migrations;

/**
 * A migration filename found in a registered directory.
 *
 * The file has not been read or executed. relativePath is relative to the
 * application base and may be null when it cannot be expressed safely.
 */
final readonly class DiscoveredFile
{
    public function __construct(
        public string $name,
        public ?string $relativePath,
        public string $directory,
        public MigrationSourceKind $source,
        public ?string $modifiedAt,
        public ?string $filenameAt,
    ) {
    }
}
