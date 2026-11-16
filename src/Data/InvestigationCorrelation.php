<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Investigation\CorrelationType;
use Beacon\Schema\Prose;

/**
 * One deterministic relationship between evidence items.
 */
final readonly class InvestigationCorrelation extends DataObject
{
    public function __construct(
        public string $sourceEvidenceId,
        public string $targetEvidenceId,
        public CorrelationType $type,
        public string $method,
        public string $explanation,
    ) {
        if (! Prose::isSafe($this->method) || ! Prose::isSafe($this->explanation)) {
            throw new \InvalidArgumentException('Investigation correlation prose must be safe.');
        }
    }
}
