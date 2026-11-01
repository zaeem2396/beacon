<?php

declare(strict_types=1);

namespace Beacon\Risk\Rules;

use Beacon\Data\RiskFinding;
use Beacon\Risk\ColumnSnapshot;
use Beacon\Risk\Confidence;
use Beacon\Risk\MigrationFacts;
use Beacon\Risk\Operation;
use Beacon\Risk\OperationKind;
use Beacon\Risk\RiskCategory;
use Beacon\Risk\Severity;

/**
 * MIG012. An explicit shorter length or precision on change() may reject existing values.
 * Schema metadata raises confidence only when it shows a real reduction.
 */
final class NarrowColumnRule extends OperationRule
{
    public function id(): string
    {
        return 'MIG012';
    }

    protected function match(MigrationFacts $facts, Operation $operation): ?RiskFinding
    {
        if ($operation->kind !== OperationKind::ChangeColumn || ($operation->length === null && $operation->precision === null)) {
            return null;
        }

        $column = $facts->table($operation->table)?->column($operation->column);

        if ($column instanceof ColumnSnapshot && ! $this->narrows($operation, $column)) {
            return null;
        }

        $known = $column instanceof ColumnSnapshot && $this->narrows($operation, $column);

        return $this->findings->make(
            $facts,
            $operation,
            $this->id(),
            RiskCategory::ExistingData,
            $operation->onNewTable ? Severity::Medium : Severity::High,
            'Reducing column length or precision can reject values',
            $known
                ? 'Schema metadata shows a shorter length or precision than the current column. Existing values were not read, so a failure is possible but not proven.'
                : 'A shorter string or a smaller decimal is declared with change. The previous length or precision is not known, so this may or may not be a reduction.',
            'Values that do not fit the new length or precision can make the migration fail.',
            'The risk disappears when every stored value already fits. That check was not run.',
            'Measure the longest stored value, or copy into a new column, before narrowing the definition.',
            $known || $operation->conditional ? null : Confidence::Medium,
        );
    }

    private function narrows(Operation $operation, ColumnSnapshot $column): bool
    {
        $shorter = $operation->length !== null && $column->characterLength !== null && $operation->length < $column->characterLength;
        $coarser = $operation->precision !== null && $column->numericPrecision !== null && (
            $operation->precision < $column->numericPrecision
            || ($operation->scale !== null && $column->numericScale !== null && $operation->scale < $column->numericScale)
        );

        return $shorter || $coarser;
    }
}
