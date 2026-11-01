<?php

declare(strict_types=1);

namespace Beacon\Risk\Rules;

use Beacon\Data\RiskFinding;
use Beacon\Risk\MigrationFacts;
use Beacon\Risk\Operation;
use Beacon\Risk\RiskCategory;
use Beacon\Risk\Severity;

/**
 * MIG014. ON DELETE CASCADE can remove child rows the application did not name.
 */
final class CascadeDeleteRule extends OperationRule
{
    public function id(): string
    {
        return 'MIG014';
    }

    protected function match(MigrationFacts $facts, Operation $operation): ?RiskFinding
    {
        if (! $operation->cascadeDelete) {
            return null;
        }

        return $this->findings->make(
            $facts,
            $operation,
            $this->id(),
            RiskCategory::ForeignKey,
            Severity::High,
            'Cascade delete removes related rows automatically',
            'ON DELETE CASCADE removes referencing rows when a parent row is deleted. This can discard data beyond the row the application deleted.',
            'A later delete of a parent row will also delete matching child rows.',
            'The impact grows with the number of child tables and rows. Those rows were not counted.',
            'Prefer a restrictive delete unless cascading removal is an explicit product rule, and review every child table.',
        );
    }
}
