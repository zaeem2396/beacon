<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Investigation\CorrelationType;
use Beacon\Schema\Prose;

/**
 * One bounded, sanitized evidence item in an investigation context.
 */
final readonly class InvestigationEvidenceItem extends DataObject
{
    /**
     * @param array<string, scalar|null> $identifiers
     */
    public function __construct(
        public string $evidenceId,
        public string $source,
        public string $type,
        public ?string $timestamp,
        public string $summary,
        public CorrelationType $correlation,
        public string $correlationReason,
        public array $identifiers,
    ) {
        if (! Prose::isSafe($this->source)
            || ! Prose::isSafe($this->type)
            || ! Prose::isSafe($this->summary)
            || ! Prose::isSafe($this->correlationReason)) {
            throw new \InvalidArgumentException('Investigation evidence prose must be safe.');
        }
    }
}
