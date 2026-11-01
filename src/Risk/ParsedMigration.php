<?php

declare(strict_types=1);

namespace Beacon\Risk;

use Beacon\Data\MigrationEntry;

/**
 * One migration file that parsed far enough to evaluate rules.
 */
final readonly class ParsedMigration
{
    public function __construct(
        public MigrationEntry $entry,
        public string $relativePath,
        public ExtractedMigration $extracted,
    ) {
    }
}
