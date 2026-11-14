<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Schema\Prose;

/**
 * Counts for one schema comparison, after filters and before pagination.
 */
final readonly class ComparisonSummary extends DataObject
{
    public function __construct(
        public int $differences,
        public int $critical,
        public int $high,
        public int $medium,
        public int $low,
        public int $informational,
        public bool $complete,
        public string $comparedAt,
        public string $summary,
    ) {
        foreach ([$differences, $critical, $high, $medium, $low, $informational] as $count) {
            if ($count < 0) {
                throw new \InvalidArgumentException('Comparison counts cannot be negative.');
            }
        }

        if ($critical + $high + $medium + $low + $informational !== $differences) {
            throw new \InvalidArgumentException('Comparison counts must add up to the difference total.');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $comparedAt) !== 1) {
            throw new \InvalidArgumentException('Comparison time must be a UTC timestamp.');
        }

        if (! Prose::isSafe($summary)) {
            throw new \InvalidArgumentException('Comparison summary must be short prose.');
        }
    }
}
