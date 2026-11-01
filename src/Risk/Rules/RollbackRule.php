<?php

declare(strict_types=1);

namespace Beacon\Risk\Rules;

use Beacon\Risk\FindingFactory;
use Beacon\Risk\MigrationFacts;
use Beacon\Risk\MigrationPhase;
use Beacon\Risk\OperationKind;
use Beacon\Risk\RiskCategory;
use Beacon\Risk\Severity;

/**
 * MIG018. up has recognized operations and down has none, or a destructive up has no restoring down.
 * A create in up with a drop in down is treated as a paired rollback, not as missing.
 */
final class RollbackRule implements RiskRule
{
    public function __construct(
        private readonly FindingFactory $findings,
    ) {
    }

    public function id(): string
    {
        return 'MIG018';
    }

    public function evaluate(MigrationFacts $facts): array
    {
        $up = [];
        $down = [];

        foreach ($facts->operations as $operation) {
            if ($operation->phase === MigrationPhase::Up) {
                $up[] = $operation;
            } else {
                $down[] = $operation;
            }
        }

        if ($up === []) {
            return [];
        }

        if ($down === []) {
            return [$this->findings->make(
                $facts,
                $up[0],
                $this->id(),
                RiskCategory::Transaction,
                Severity::Medium,
                'Rollback does not show a reversing operation',
                $facts->downPresent
                    ? 'up contains recognized operations and down contains none. A missing rollback is not proof the forward migration is unsafe, but it removes an automated way back.'
                    : 'No down method was found. There is no recognized rollback for the forward operations.',
                'Without a down operation there is no automated reverse step in this file.',
                'Some migrations are intentionally one way. That intent was not inferred from the filename.',
                'Write a down method that reverses the schema change, or document why the migration is one way.',
            )];
        }

        $destructive = false;

        foreach ($up as $operation) {
            if (in_array($operation->kind, [OperationKind::DropTable, OperationKind::DropColumn, OperationKind::Truncate], true)) {
                $destructive = true;
            }
        }

        $restores = false;

        foreach ($down as $operation) {
            if (in_array($operation->kind, [OperationKind::CreateTable, OperationKind::AddColumn], true)) {
                $restores = true;
            }
        }

        if (! $destructive || $restores) {
            return [];
        }

        return [$this->findings->make(
            $facts,
            $up[0],
            $this->id(),
            RiskCategory::Transaction,
            Severity::Medium,
            'Rollback does not restore a destructive change',
            'down does not show an operation that restores the table or column removed in up. A rollback would not recover the removed data.',
            'Dropping or truncating data cannot be undone by an unrelated down operation.',
            'A later migration may restore the structure. This file does not show that restore.',
            'Keep a backup, and make down recreate the structure only if the data loss is acceptable.',
        )];
    }
}
