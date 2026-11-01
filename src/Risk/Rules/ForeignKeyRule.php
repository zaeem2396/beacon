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
 * MIG006. Adding a foreign key can fail validation and can lock the table.
 * Lock duration is not measured. Cascade delete is also reported by MIG014.
 */
final class ForeignKeyRule extends OperationRule
{
    public function id(): string
    {
        return 'MIG006';
    }

    protected function match(MigrationFacts $facts, Operation $operation): ?RiskFinding
    {
        $foreign = $operation->kind === OperationKind::AddForeign || $operation->foreign;

        if (! $foreign) {
            return null;
        }

        return $this->findings->make(
            $facts,
            $operation,
            $this->id(),
            RiskCategory::ForeignKey,
            $operation->onNewTable ? Severity::Low : Severity::Medium,
            'Adding a foreign key can fail on existing rows',
            'A foreign key fails when referenced rows are missing. It can also lock the table while the constraint is validated. Potential table-locking or blocking risk. Actual behavior depends on the database engine and operation.',
            'Orphaned rows or incompatible column types can make the constraint fail.',
            'A new table has no orphaned rows yet. An existing table needs a data check that this analysis did not run.',
            'Clean orphaned rows, match column types, and add the constraint when the application can tolerate the validation.',
        );
    }
}
