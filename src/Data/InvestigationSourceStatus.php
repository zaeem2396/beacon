<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Schema\Prose;

/**
 * Collection status for one evidence source within an investigation.
 */
final readonly class InvestigationSourceStatus extends DataObject
{
    public function __construct(
        public string $source,
        public string $status,
        public bool $truncated,
        public int $returned,
        public ?string $detail,
    ) {
        if (! Prose::isSafe($this->source) || ! Prose::isSafe($this->status)) {
            throw new \InvalidArgumentException('Investigation source status prose must be safe.');
        }

        if ($this->detail !== null && ! Prose::isSafe($this->detail)) {
            throw new \InvalidArgumentException('Investigation source detail must be safe prose.');
        }
    }
}
