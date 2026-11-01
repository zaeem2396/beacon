<?php

declare(strict_types=1);

namespace Beacon\Risk\Rules;

use Beacon\Data\RiskFinding;
use Beacon\Risk\MigrationFacts;
use Beacon\Risk\Operation;
use Beacon\Risk\RiskCategory;
use Beacon\Risk\Severity;

/**
 * MIG020. change() that tightens nullability, sets a default, or restricts an enum.
 * Existing values were not read, so failure is conditional.
 */
final class NullabilityRule extends OperationRule
{
    public function id(): string
    {
        return 'MIG020';
    }

    protected function match(MigrationFacts $facts, Operation $operation): ?RiskFinding
    {
        if (! $operation->change) {
            return null;
        }

        $enum = $operation->columnType === 'enum';
        $tight = ! $operation->nullable && ! $operation->hasDefault;
        $default = $operation->hasDefault;

        if (! $enum && ! $tight && ! $default) {
            return null;
        }

        $severity = Severity::Medium;

        if (! $operation->onNewTable && ($tight || $enum)) {
            $severity = Severity::High;
        }

        if ($operation->onNewTable) {
            $severity = Severity::Low;
        }

        return $this->findings->make(
            $facts,
            $operation,
            $this->id(),
            RiskCategory::ExistingData,
            $severity,
            'Nullability or default handling can reject existing rows',
            'Making a column non-nullable, changing its default, or restricting an enum can fail or change writes when rows already exist. Existing values were not read.',
            'Rows that do not satisfy the new restriction can make the migration fail.',
            'A nullable or new column avoids that failure. A populated column does not.',
            'Backfill a value for every existing row before making the column required or narrowing the allowed values.',
        );
    }
}
