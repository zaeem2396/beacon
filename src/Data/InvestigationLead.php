<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Schema\Prose;

/**
 * One deterministic investigation lead. Not a root-cause claim.
 */
final readonly class InvestigationLead extends DataObject
{
    /**
     * @param list<string> $evidenceRefs
     */
    public function __construct(
        public string $ruleId,
        public string $title,
        public string $explanation,
        public array $evidenceRefs,
        public string $evidenceClass,
        public string $nextInspection,
        public string $limitation,
    ) {
        if (! Prose::isSafe($this->ruleId)
            || ! Prose::isSafe($this->title)
            || ! Prose::isSafe($this->explanation)
            || ! Prose::isSafe($this->evidenceClass)
            || ! Prose::isSafe($this->nextInspection)
            || ! Prose::isSafe($this->limitation)) {
            throw new \InvalidArgumentException('Investigation lead prose must be safe.');
        }
    }
}
