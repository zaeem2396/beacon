<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Schema\Prose;

/**
 * Counts for the filtered migration inspection, before pagination.
 */
final readonly class MigrationSummary extends DataObject
{
    public function __construct(
        public int $fileCount,
        public int $executed,
        public int $pending,
        public int $missingFiles,
        public int $duplicateNames,
        public int $batchCount,
        public string $inspectedAt,
        public string $summary,
    ) {
        foreach ([$fileCount, $executed, $pending, $missingFiles, $duplicateNames, $batchCount] as $count) {
            if ($count < 0) {
                throw new \InvalidArgumentException('Migration counts cannot be negative.');
            }
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $inspectedAt) !== 1) {
            throw new \InvalidArgumentException('Migration inspection time must be a UTC timestamp.');
        }

        if (! Prose::isSafe($summary)) {
            throw new \InvalidArgumentException('Migration summary must be short prose.');
        }
    }
}
