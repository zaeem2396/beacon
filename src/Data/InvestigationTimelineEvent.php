<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Investigation\CorrelationType;
use Beacon\Schema\Prose;

/**
 * One chronological investigation timeline event.
 */
final readonly class InvestigationTimelineEvent extends DataObject
{
    /**
     * @param array<string, scalar|null> $identifiers
     */
    public function __construct(
        public string $eventId,
        public string $eventType,
        public ?string $timestamp,
        public string $source,
        public string $summary,
        public CorrelationType $correlation,
        public array $identifiers,
    ) {
        if (! Prose::isSafe($this->eventType)
            || ! Prose::isSafe($this->source)
            || ! Prose::isSafe($this->summary)) {
            throw new \InvalidArgumentException('Investigation timeline prose must be safe.');
        }
    }
}
