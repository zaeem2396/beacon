<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * How many distinct migration names were recorded in one batch.
 *
 * Duplicate files that share a name are counted once. This is not an
 * execution timestamp.
 */
final readonly class MigrationBatchSummary extends DataObject
{
    public function __construct(
        public int $batch,
        public int $count,
    ) {
        if ($batch < 1 || $batch > 1000000 || $count < 1) {
            throw new \InvalidArgumentException('Migration batch summary is out of range.');
        }
    }
}
