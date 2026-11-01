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
 * MIG007. change() or a generic ALTER can rebuild a column.
 * This does not prove the types are incompatible. Nullability transitions are MIG020.
 */
final class ChangeColumnRule extends OperationRule
{
    public function id(): string
    {
        return 'MIG007';
    }

    protected function match(MigrationFacts $facts, Operation $operation): ?RiskFinding
    {
        if ($operation->kind !== OperationKind::ChangeColumn) {
            return null;
        }

        return $this->findings->make(
            $facts,
            $operation,
            $this->id(),
            RiskCategory::Compatibility,
            $operation->onNewTable ? Severity::Medium : Severity::High,
            'Changing a column can rewrite stored values',
            'Calling change, or altering a column, can rebuild the table or reject values. The previous type was not assumed to differ. Lock duration was not measured.',
            'Existing readers and stored values can disagree with the new definition.',
            'The risk is lower when the table is created in this migration. It is higher when rows already use the old type.',
            'Add a new column, copy values, and switch readers before dropping the old column.',
        );
    }
}
