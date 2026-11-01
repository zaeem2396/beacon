<?php

declare(strict_types=1);

namespace Beacon\Risk\Rules;

use Beacon\Risk\Confidence;
use Beacon\Risk\FindingFactory;
use Beacon\Risk\MigrationFacts;
use Beacon\Risk\MigrationPhase;
use Beacon\Risk\Operation;
use Beacon\Risk\OperationKind;
use Beacon\Risk\RiskCategory;
use Beacon\Risk\Severity;

/**
 * MIG017. Several existing-table schema changes, raw DDL, or a transaction around DDL.
 * MySQL often commits DDL implicitly. This is not a measured downtime figure.
 */
final class TransactionRule implements RiskRule
{
    public function __construct(
        private readonly FindingFactory $findings,
    ) {
    }

    public function id(): string
    {
        return 'MIG017';
    }

    public function evaluate(MigrationFacts $facts): array
    {
        $found = [];

        foreach (MigrationPhase::cases() as $phase) {
            $schema = [];
            $transaction = null;

            foreach ($facts->operations as $operation) {
                if ($operation->phase !== $phase) {
                    continue;
                }

                if ($operation->kind === OperationKind::Transaction) {
                    $transaction = $operation;
                }

                if ($this->schema($operation)) {
                    $schema[] = $operation;
                }
            }

            $raw = $this->raw($schema);
            $wrapped = $transaction !== null && $schema !== [];

            if (count($schema) < 2 && ! $wrapped && ! $raw) {
                continue;
            }

            $sample = $schema[0] ?? $transaction;

            if ($sample === null) {
                continue;
            }

            $found[] = $this->findings->make(
                $facts,
                $sample,
                $this->id(),
                RiskCategory::Transaction,
                $wrapped ? Severity::Medium : Severity::Informational,
                'Schema changes may not share one transaction',
                'MySQL and MariaDB commonly commit schema changes immediately. An explicit transaction does not make every schema statement atomic on those drivers. Other drivers differ. This is not a measured outage.',
                'A failure between schema statements can leave a partial change.',
                'Drivers with transactional DDL reduce this risk. That behavior was not assumed for every connection.',
                'Do not rely on rolling back a partial schema change unless the target driver documents transactional DDL.',
                $wrapped ? Confidence::Medium : Confidence::Medium,
                $wrapped,
            );
        }

        return $found;
    }

    /**
     * @param list<Operation> $operations
     */
    private function raw(array $operations): bool
    {
        foreach ($operations as $operation) {
            if ($operation->sqlEffect !== null && $operation->sqlEffect !== 'unrecognized' && $operation->sqlEffect !== 'select_rows' && $operation->sqlEffect !== 'update_rows') {
                return true;
            }
        }

        return false;
    }

    private function schema(Operation $operation): bool
    {
        if ($operation->onNewTable) {
            return false;
        }

        return in_array($operation->kind, [
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
            OperationKind::Truncate,
        ], true);
    }
}
