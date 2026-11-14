<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Comparison\DifferenceCategory;
use Beacon\Comparison\Severity;

/**
 * The filters and snapshot identities used for one comparison.
 */
final readonly class ComparisonScope extends DataObject
{
    public function __construct(
        public bool $filtered,
        public ?DifferenceCategory $category,
        public ?Severity $minimumSeverity,
        public ?string $object,
        public bool $detectRenames,
        public bool $baselineLive,
        public bool $candidateLive,
        public string $baselineId,
        public string $candidateId,
    ) {
        if (preg_match('/^[a-f0-9]{16}$/', $baselineId) !== 1 || preg_match('/^[a-f0-9]{16}$/', $candidateId) !== 1) {
            throw new \InvalidArgumentException('Comparison snapshot identifiers must be 16 hexadecimal characters.');
        }
    }
}
