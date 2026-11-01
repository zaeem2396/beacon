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
 * MIG013. Set-based updates are medium. Row-by-row reads without chunking are high.
 * Chunk or chunkById lowers the finding. A single update is not automatically high.
 */
final class BackfillRule extends OperationRule
{
    public function id(): string
    {
        return 'MIG013';
    }

    protected function match(MigrationFacts $facts, Operation $operation): ?RiskFinding
    {
        if (! in_array($operation->kind, [OperationKind::UpdateRows, OperationKind::ReadRows, OperationKind::DeleteRows], true)) {
            return null;
        }

        if ($operation->sqlEffect === 'delete_rows') {
            return null;
        }

        $deletes = $operation->kind === OperationKind::DeleteRows;
        $severity = Severity::Medium;
        $title = 'A data backfill may scan or rewrite many rows';
        $explanation = 'Updating rows inside a migration can run for a long time. This analysis did not count the rows. A single set-based update is not treated as a row-by-row loop.';

        if ($operation->chunked) {
            $severity = Severity::Low;
            $title = 'A batched backfill was recognized';
            $explanation = 'The migration uses chunk or chunkById. Batching reduces the size of each write, but the total work can still be large.';
        } elseif ($operation->rowByRow) {
            $severity = Severity::High;
            $title = 'A backfill may walk rows without a visible batch size';
            $explanation = 'The migration reads rows with get, cursor, lazy, or each and no chunk or chunkById was visible. The number of rows was not counted.';
        } elseif ($deletes) {
            $severity = Severity::High;
            $title = 'Deleting rows in a migration can discard data';
            $explanation = 'A delete statement can remove many existing rows. The statement was not executed and the affected row count was not measured.';
        }

        return $this->findings->make(
            $facts,
            $operation,
            $this->id(),
            $deletes ? RiskCategory::DataLoss : RiskCategory::Performance,
            $severity,
            $title,
            $explanation,
            'Large tables make unbounded reads, updates, and deletes expensive or destructive.',
            'A visible chunk size lowers the risk. A where clause may also bound it, but the row count is still unknown.',
            'Batch the work and move large backfills out of the schema migration when possible.',
        );
    }
}
