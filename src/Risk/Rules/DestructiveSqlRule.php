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
 * MIG015. Literal SQL that drops a database, table, or column, or deletes rows.
 * The SQL is not executed. Unrecognized SQL is MIG021.
 */
final class DestructiveSqlRule extends OperationRule
{
    public function id(): string
    {
        return 'MIG015';
    }

    protected function match(MigrationFacts $facts, Operation $operation): ?RiskFinding
    {
        if ($operation->kind !== OperationKind::RawSql) {
            return null;
        }

        $critical = in_array($operation->sqlEffect, ['drop_database', 'drop_table', 'truncate'], true);
        $high = in_array($operation->sqlEffect, ['drop_column', 'delete_rows'], true);

        if (! $critical && ! $high) {
            return null;
        }

        return $this->findings->make(
            $facts,
            $operation,
            $this->id(),
            RiskCategory::DataLoss,
            $critical ? Severity::Critical : Severity::High,
            'Raw SQL appears to destroy data or schema',
            'A literal SQL statement was classified as destructive. It was not executed. Dynamic values inside the literal were not queried.',
            'DROP, TRUNCATE, and DELETE statements remove schema or rows when they run.',
            'A guarded or commented statement can still be recognized. A dynamic statement is reported separately and with low confidence.',
            'Review the statement manually and run it only with a backup and a tested rollback.',
            $operation->conditional ? Confidence::Medium : Confidence::High,
        );
    }
}
