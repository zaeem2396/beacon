<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Schema\Prose;

/**
 * Counts for the filtered schema, before pagination.
 */
final readonly class SchemaSummary extends DataObject
{
    public function __construct(
        public int $tableCount,
        public int $viewCount,
        public string $inspectedAt,
        public string $summary,
    ) {
        if ($tableCount < 0 || $viewCount < 0) {
            throw new \InvalidArgumentException('Schema counts cannot be negative.');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $inspectedAt) !== 1) {
            throw new \InvalidArgumentException('Schema inspection time must be a UTC timestamp.');
        }

        if (! Prose::isSafe($summary)) {
            throw new \InvalidArgumentException('Schema summary must be short prose.');
        }
    }
}
