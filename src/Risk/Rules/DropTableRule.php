<?php

declare(strict_types=1);

namespace Beacon\Risk\Rules;

use Beacon\Data\RiskFinding;
use Beacon\Risk\MigrationFacts;
use Beacon\Risk\MigrationPhase;
use Beacon\Risk\Operation;
use Beacon\Risk\OperationKind;
use Beacon\Risk\RiskCategory;
use Beacon\Risk\Severity;

/**
 * MIG001. A schema-builder table drop is critical in up.
 * A down drop of a table created in up is the usual rollback and stays informational.
 * Raw DROP TABLE is MIG015, not this rule.
 */
final class DropTableRule extends OperationRule
{
    public function id(): string
    {
        return 'MIG001';
    }

    protected function match(MigrationFacts $facts, Operation $operation): ?RiskFinding
    {
        if ($operation->kind !== OperationKind::DropTable || $operation->sqlEffect !== null) {
            return null;
        }

        $rollback = $operation->phase === MigrationPhase::Down && $facts->createdInUp($operation->table);

        return $this->findings->make(
            $facts,
            $operation,
            $this->id(),
            RiskCategory::DataLoss,
            $rollback ? Severity::Informational : Severity::Critical,
            $rollback ? 'Rollback drops a table created in up' : 'Dropping a table can destroy existing rows',
            $rollback
                ? 'down drops a table that up creates. This is the usual rollback and does not by itself remove a pre-existing table.'
                : 'Schema drop or dropIfExists removes a table and the rows stored in it. Whether rows exist was not checked by reading table data.',
            'A table drop is destructive when the table is already in use.',
            'The impact is lower when this migration creates the table and the drop is only in down. Existing rows make it critical.',
            'Confirm the table is unused, take a backup, and deploy the drop only after application code no longer reads it.',
            manualReview: ! $rollback,
        );
    }
}
