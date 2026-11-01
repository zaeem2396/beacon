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
 * MIG021. A literal SQL statement that matched no known shape.
 * It is not ignored and it is not treated as safe.
 */
final class UnrecognizedSqlRule extends OperationRule
{
    public function id(): string
    {
        return 'MIG021';
    }

    protected function match(MigrationFacts $facts, Operation $operation): ?RiskFinding
    {
        if ($operation->kind !== OperationKind::RawSql || $operation->sqlEffect !== 'unrecognized') {
            return null;
        }

        return $this->findings->make(
            $facts,
            $operation,
            $this->id(),
            RiskCategory::DataLoss,
            Severity::Medium,
            'Raw SQL was not recognized',
            'A literal SQL statement did not match a known pattern. It was not executed and it was not treated as safe.',
            'Unrecognized SQL can still change schema or rows when the migration runs.',
            'A known statement shape would use a more specific rule.',
            'Review the statement manually before this migration is deployed.',
            Confidence::Low,
            true,
        );
    }
}
