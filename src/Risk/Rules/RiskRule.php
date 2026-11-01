<?php

declare(strict_types=1);

namespace Beacon\Risk\Rules;

use Beacon\Data\RiskFinding;
use Beacon\Risk\MigrationFacts;

/**
 * One deterministic risk rule.
 */
interface RiskRule
{
    public function id(): string;

    /**
     * @return list<RiskFinding>
     */
    public function evaluate(MigrationFacts $facts): array;
}
