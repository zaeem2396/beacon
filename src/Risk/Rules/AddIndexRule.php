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
 * MIG010. An index on a new table is informational.
 * An index on an existing table is low, or medium when the row estimate is large.
 * The estimate is not a measured lock time.
 */
final class AddIndexRule extends OperationRule
{
    public const LARGE_TABLE = 100000;

    public function id(): string
    {
        return 'MIG010';
    }

    protected function match(MigrationFacts $facts, Operation $operation): ?RiskFinding
    {
        $index = $operation->kind === OperationKind::AddIndex || ($operation->index && $operation->kind === OperationKind::AddColumn);

        if (! $index) {
            return null;
        }

        if ($operation->onNewTable) {
            return $this->findings->make(
                $facts,
                $operation,
                $this->id(),
                RiskCategory::Locking,
                Severity::Informational,
                'An index on a newly created table is declared',
                'The index is part of a table this migration creates, so it does not rebuild an existing populated table.',
                'Index creation on an empty new table is ordinary schema declaration.',
                'The risk rises only if this statement instead alters a table that already has rows.',
                'No extra step is required for an index on a table created in the same migration.',
                manualReview: false,
            );
        }

        $estimate = $facts->table($operation->table)?->rowEstimate;
        $large = $estimate !== null && $estimate >= self::LARGE_TABLE;

        return $this->findings->make(
            $facts,
            $operation,
            $this->id(),
            RiskCategory::Locking,
            $large ? Severity::Medium : Severity::Low,
            'Adding an index can lock or slow writes',
            'Building an index on an existing table can block writes or take a long time. Potential table-locking or blocking risk. Actual behavior depends on the database engine and operation. A row estimate, when present, is not a count.',
            'Large existing tables make index builds more expensive. Table size was not measured by scanning rows.',
            'An online algorithm, a small table, or a new table lowers the operational risk. None of those was guaranteed here.',
            'Prefer an online index algorithm when the table is large, and build it outside the busiest period.',
        );
    }
}
