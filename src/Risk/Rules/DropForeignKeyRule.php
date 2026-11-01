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
 * MIG022. Dropping a foreign key removes a check and can allow new orphaned rows.
 */
final class DropForeignKeyRule extends OperationRule
{
    public function id(): string
    {
        return 'MIG022';
    }

    protected function match(MigrationFacts $facts, Operation $operation): ?RiskFinding
    {
        if ($operation->kind !== OperationKind::DropForeign) {
            return null;
        }

        return $this->findings->make(
            $facts,
            $operation,
            $this->id(),
            RiskCategory::ForeignKey,
            $operation->onNewTable ? Severity::Low : Severity::Medium,
            'Dropping a foreign key removes a referential check',
            'Without the foreign key, new rows can reference missing parents. Existing rows were not checked.',
            'The database will no longer reject orphaned references.',
            'The impact depends on whether application code still expects the constraint.',
            'Confirm no writer depends on the database to reject missing parents before dropping the key.',
        );
    }
}
