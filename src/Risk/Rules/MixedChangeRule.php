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
 * MIG019. Schema changes and row changes in the same method can apply partially.
 */
final class MixedChangeRule implements RiskRule
{
    public function __construct(
        private readonly FindingFactory $findings,
    ) {
    }

    public function id(): string
    {
        return 'MIG019';
    }

    public function evaluate(MigrationFacts $facts): array
    {
        $found = [];

        foreach (MigrationPhase::cases() as $phase) {
            $schema = null;
            $data = false;

            foreach ($facts->operations as $operation) {
                if ($operation->phase !== $phase) {
                    continue;
                }

                if ($this->schema($operation->kind)) {
                    $schema ??= $operation;
                }

                if (in_array($operation->kind, [OperationKind::UpdateRows, OperationKind::ReadRows, OperationKind::DeleteRows], true)) {
                    $data = true;
                }
            }

            if ($schema === null || ! $data) {
                continue;
            }

            $found[] = $this->findings->make(
                $facts,
                $schema,
                $this->id(),
                RiskCategory::Transaction,
                Severity::Medium,
                'Schema changes and data changes are in one migration',
                'The same method changes schema and also reads or writes rows. A failure can leave schema and data changes partially applied, depending on the driver.',
                'Schema statements and data statements do not always roll back together.',
                'Drivers that wrap both in one transaction lower the risk. MySQL DDL often does not.',
                'Separate data backfills from schema changes so each migration has one reason to fail.',
            );
        }

        return $found;
    }

    private function schema(OperationKind $kind): bool
    {
        return in_array($kind, [
            OperationKind::CreateTable,
            OperationKind::DropTable,
            OperationKind::RenameTable,
            OperationKind::AddColumn,
            OperationKind::DropColumn,
            OperationKind::RenameColumn,
            OperationKind::ChangeColumn,
            OperationKind::AddIndex,
            OperationKind::AddUnique,
            OperationKind::DropIndex,
            OperationKind::AddForeign,
            OperationKind::DropForeign,
        ], true);
    }
}
