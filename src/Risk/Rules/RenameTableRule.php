<?php

declare(strict_types=1);

namespace Beacon\Risk\Rules;

use Beacon\Data\RiskFinding;
use Beacon\Risk\MigrationFacts;
use Beacon\Risk\Operation;
use Beacon\Risk\OperationKind;
use Beacon\Risk\RiskCategory;
use Beacon\Risk\Severity;

/**
 * MIG009. A table rename is a compatibility risk, not automatic data loss.
 */
final class RenameTableRule extends OperationRule
{
    public function id(): string
    {
        return 'MIG009';
    }

    protected function match(MigrationFacts $facts, Operation $operation): ?RiskFinding
    {
        if ($operation->kind !== OperationKind::RenameTable) {
            return null;
        }

        return $this->findings->make(
            $facts,
            $operation,
            $this->id(),
            RiskCategory::Compatibility,
            Severity::High,
            'Renaming a table can break current application code',
            'A table rename is not classified as data loss. Queries and constraints that use the previous name can fail until every caller is updated.',
            'The table remains, but its name changes underneath existing code.',
            'A single-instance deploy can hide the break. A rolling deploy will not.',
            'Update every caller in the same release window, or keep a compatibility name until they move.',
        );
    }
}
