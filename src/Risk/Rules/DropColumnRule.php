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
 * MIG002. Dropping a column discards stored values when the table already has rows.
 * The same call inside Schema::create is low because that table is new in this migration.
 */
final class DropColumnRule extends OperationRule
{
    public function id(): string
    {
        return 'MIG002';
    }

    protected function match(MigrationFacts $facts, Operation $operation): ?RiskFinding
    {
        if ($operation->kind !== OperationKind::DropColumn || $operation->sqlEffect !== null) {
            return null;
        }

        $snapshot = $facts->table($operation->table);
        $severity = $operation->onNewTable || ($snapshot !== null && ! $snapshot->exists) ? Severity::Low : Severity::High;

        return $this->findings->make(
            $facts,
            $operation,
            $this->id(),
            RiskCategory::DataLoss,
            $severity,
            'Dropping a column can discard stored values',
            'Removing a column discards values already stored in that column when the table has rows. This analysis did not read those rows.',
            'Column removal is destructive for data already stored under that name.',
            'The risk is lower on a table this migration creates. It is higher when the table already has rows.',
            'Stop reading and writing the column, copy any values that must survive, and drop it in a later migration.',
        );
    }
}
