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
 * MIG011. Dropping an index can change query plans or remove a uniqueness guarantee.
 */
final class DropIndexRule extends OperationRule
{
    public function id(): string
    {
        return 'MIG011';
    }

    protected function match(MigrationFacts $facts, Operation $operation): ?RiskFinding
    {
        if ($operation->kind !== OperationKind::DropIndex) {
            return null;
        }

        return $this->findings->make(
            $facts,
            $operation,
            $this->id(),
            RiskCategory::Compatibility,
            $operation->onNewTable ? Severity::Low : Severity::Medium,
            'Dropping an index can change query plans',
            'Removing an index can make existing queries slower or remove a uniqueness guarantee. Whether queries depend on it was not proven from application code.',
            'Queries that filter or join on the indexed columns can get slower.',
            'The impact is small when the index was created and dropped in the same new-table migration.',
            'Check query plans that filter or join on these columns before dropping the index.',
        );
    }
}
