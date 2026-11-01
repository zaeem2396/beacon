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
 * MIG005. Unique constraints can fail when duplicate values already exist.
 * The analyzer does not scan row values. A unique index on a new table stays low.
 */
final class UniqueConstraintRule extends OperationRule
{
    public function id(): string
    {
        return 'MIG005';
    }

    protected function match(MigrationFacts $facts, Operation $operation): ?RiskFinding
    {
        $unique = $operation->kind === OperationKind::AddUnique || $operation->unique;

        if (! $unique || $operation->sqlEffect === 'unrecognized') {
            return null;
        }

        return $this->findings->make(
            $facts,
            $operation,
            $this->id(),
            RiskCategory::ExistingData,
            $operation->onNewTable ? Severity::Low : Severity::Medium,
            'Adding a unique constraint can fail on existing rows',
            'A unique index fails if duplicate values already exist. This analysis did not scan row values. Building it can also lock or slow writes, depending on the engine.',
            'Duplicate values, not the index syntax itself, are what make the migration fail.',
            'A table created in this migration has no pre-existing duplicates. An existing table might.',
            'Find and resolve duplicate values before adding the unique constraint.',
        );
    }
}
