<?php

declare(strict_types=1);

namespace Beacon\Migrations;

use Beacon\Data\MigrationBatchSummary;
use Beacon\Data\MigrationEntry;

/**
 * Classified migrations after filters and before pagination.
 *
 * Warning flags describe the full comparison. Counts describe the filtered set.
 */
final readonly class CatalogResult
{
    /**
     * @param list<MigrationEntry> $migrations
     * @param list<MigrationBatchSummary> $batches
     */
    public function __construct(
        public array $migrations,
        public array $batches,
        public int $fileCount,
        public int $executed,
        public int $pending,
        public int $missingFiles,
        public int $duplicateNames,
        public bool $duplicates,
        public bool $missing,
        public bool $withheld,
        public bool $batchesTruncated,
    ) {
    }
}
