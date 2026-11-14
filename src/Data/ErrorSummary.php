<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Schema\Prose;

/**
 * Window-scoped error metrics. Counts are not historical trends.
 */
final readonly class ErrorSummary extends DataObject
{
    /**
     * @param array<string, int> $bySeverity
     * @param array<string, int> $byCategory
     * @param array<string, int> $byClass
     */
    public function __construct(
        public int $inspectedRecords,
        public int $matchedRecords,
        public int $uniqueGroups,
        public int $returnedGroups,
        public array $bySeverity,
        public array $byCategory,
        public array $byClass,
        public ?string $windowStart,
        public ?string $windowEnd,
        public bool $truncated,
        public string $limitation,
    ) {
        if (! Prose::isSafe($limitation)) {
            throw new \InvalidArgumentException('Error summary limitation must be safe prose.');
        }
    }
}
