<?php

declare(strict_types=1);

namespace Beacon\Risk\Rules;

use Beacon\Data\RiskFinding;
use Beacon\Risk\FindingFactory;
use Beacon\Risk\MigrationFacts;
use Beacon\Risk\Operation;

/**
 * A rule that looks at each recognized operation.
 */
abstract class OperationRule implements RiskRule
{
    public function __construct(
        protected readonly FindingFactory $findings,
    ) {
    }

    public function evaluate(MigrationFacts $facts): array
    {
        $found = [];

        foreach ($facts->operations as $operation) {
            $finding = $this->match($facts, $operation);

            if ($finding instanceof RiskFinding) {
                $found[] = $finding;
            }
        }

        return $found;
    }

    abstract protected function match(MigrationFacts $facts, Operation $operation): ?RiskFinding;
}
