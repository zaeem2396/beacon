<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Schema\Prose;

/**
 * One unavailable or incomplete evidence note.
 */
final readonly class InvestigationMissingEvidence extends DataObject
{
    public function __construct(
        public string $code,
        public string $source,
        public string $detail,
    ) {
        if (! Prose::isSafe($this->code)
            || ! Prose::isSafe($this->source)
            || ! Prose::isSafe($this->detail)) {
            throw new \InvalidArgumentException('Investigation missing evidence prose must be safe.');
        }
    }
}
