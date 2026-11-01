<?php

declare(strict_types=1);

namespace Beacon\Risk\Rules;

use Beacon\Data\RiskFinding;
use Beacon\Risk\Confidence;
use Beacon\Risk\MigrationFacts;
use Beacon\Risk\Operation;
use Beacon\Risk\OperationKind;
use Beacon\Risk\RiskCategory;
use Beacon\Risk\Severity;

/**
 * MIG016. SQL built from variables, concatenation, or interpolation is not classified.
 */
final class DynamicSqlRule extends OperationRule
{
    public function id(): string
    {
        return 'MIG016';
    }

    protected function match(MigrationFacts $facts, Operation $operation): ?RiskFinding
    {
        if ($operation->kind !== OperationKind::DynamicSql) {
            return null;
        }

        return $this->findings->make(
            $facts,
            $operation,
            $this->id(),
            RiskCategory::DataLoss,
            Severity::Medium,
            'Dynamic SQL could not be classified',
            'Unable to statically determine the impact of this dynamically constructed SQL statement. Manual review is required.',
            'The statement text is not a literal, so a drop or an update cannot be distinguished.',
            'A literal statement would receive a specific rule. A concatenated statement does not.',
            'Replace dynamic SQL with a literal statement or review the constructed SQL before deployment.',
            Confidence::Low,
            true,
        );
    }
}
