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
 * MIG003. Truncate removes every row. The statement is not executed here.
 */
final class TruncateTableRule extends OperationRule
{
    public function id(): string
    {
        return 'MIG003';
    }

    protected function match(MigrationFacts $facts, Operation $operation): ?RiskFinding
    {
        if ($operation->kind !== OperationKind::Truncate) {
            return null;
        }

        return $this->findings->make(
            $facts,
            $operation,
            $this->id(),
            RiskCategory::DataLoss,
            Severity::Critical,
            'Truncating a table removes its current rows',
            'Truncate deletes every row in the table. This is destructive even when the table structure remains.',
            'A truncate does not keep the current rows.',
            'The table is unusable for readers that expect the deleted rows to remain.',
            'Confirm the table is disposable and take a backup before truncating it.',
        );
    }
}
