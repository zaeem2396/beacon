<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Schema\Prose;

/**
 * Counts for the filtered finding set, before pagination.
 */
final readonly class RiskSummary extends DataObject
{
    public function __construct(
        public int $analyzed,
        public int $findings,
        public int $critical,
        public int $high,
        public int $medium,
        public int $low,
        public int $informational,
        public int $manualReview,
        public string $inspectedAt,
        public string $summary,
    ) {
        if ($analyzed < 0 || $findings < 0 || $critical < 0 || $high < 0 || $medium < 0 || $low < 0 || $informational < 0 || $manualReview < 0) {
            throw new \InvalidArgumentException('Risk summary counts cannot be negative.');
        }

        if ($critical + $high + $medium + $low + $informational !== $findings) {
            throw new \InvalidArgumentException('Risk severity counts must add up to the finding count.');
        }

        if ($manualReview > $findings) {
            throw new \InvalidArgumentException('Manual review count cannot exceed the finding count.');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $inspectedAt) !== 1) {
            throw new \InvalidArgumentException('Risk inspection time must be a UTC timestamp.');
        }

        if (! Prose::isSafe($summary)) {
            throw new \InvalidArgumentException('Risk summary must be short prose.');
        }
    }
}
