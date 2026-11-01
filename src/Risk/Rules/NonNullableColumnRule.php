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
 * MIG004. A non-nullable column without a default can fail on a populated table.
 * Columns declared inside Schema::create are not reported, because that table starts empty.
 */
final class NonNullableColumnRule extends OperationRule
{
    public function id(): string
    {
        return 'MIG004';
    }

    protected function match(MigrationFacts $facts, Operation $operation): ?RiskFinding
    {
        if ($operation->kind !== OperationKind::AddColumn || $operation->onNewTable || $operation->nullable || $operation->hasDefault || $operation->change) {
            return null;
        }

        $snapshot = $facts->table($operation->table);
        $severity = Severity::High;

        if ($snapshot !== null && ! $snapshot->exists) {
            $severity = Severity::Low;
        } elseif ($snapshot !== null && $snapshot->rowEstimate === 0) {
            $severity = Severity::Medium;
        }

        return $this->findings->make(
            $facts,
            $operation,
            $this->id(),
            RiskCategory::ExistingData,
            $severity,
            'Adding a non-nullable column without a default',
            'A non-nullable column without a default can fail when the table already contains rows. An empty or missing table does not have that failure mode. Row estimates are not a count.',
            'Existing rows would have no value for the new column.',
            'The migration fails only when the table already has rows and the database rejects a missing default. That was not proven by reading rows.',
            'Add the column as nullable or with a default, backfill values, and tighten the constraint in a later migration.',
        );
    }
}
