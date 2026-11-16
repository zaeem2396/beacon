<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Investigation\SubjectKind;
use Beacon\Schema\Prose;

/**
 * Primary subject of an investigation when identifiable.
 */
final readonly class InvestigationSubject extends DataObject
{
    public function __construct(
        public SubjectKind $kind,
        public ?string $requestId,
        public ?string $correlationId,
        public ?string $exceptionId,
        public ?string $fingerprint,
        public ?string $endpoint,
        public ?string $summary,
    ) {
        if ($this->summary !== null && ! Prose::isSafe($this->summary)) {
            throw new \InvalidArgumentException('Investigation subject summary must be safe prose.');
        }
    }
}
