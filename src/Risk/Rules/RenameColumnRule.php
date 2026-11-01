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
 * MIG008. A column rename is a compatibility risk, not automatic data loss.
 */
final class RenameColumnRule extends OperationRule
{
    public function id(): string
    {
        return 'MIG008';
    }

    protected function match(MigrationFacts $facts, Operation $operation): ?RiskFinding
    {
        if ($operation->kind !== OperationKind::RenameColumn) {
            return null;
        }

        return $this->findings->make(
            $facts,
            $operation,
            $this->id(),
            RiskCategory::Compatibility,
            $operation->onNewTable ? Severity::Low : Severity::High,
            'Renaming a column can break current application code',
            'A rename is not classified as data loss. Older code that still uses the previous name can fail during a rolling deployment.',
            'Application code and the column name can diverge until every caller is updated.',
            'The risk grows when more than one application instance is deployed at a time.',
            'Use an expand and contract sequence. Add the new name, write both, migrate readers, then remove the old name.',
        );
    }
}
