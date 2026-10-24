<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Routes;

use Beacon\Data\Finding;
use Beacon\Data\FindingEvidence;
use Beacon\Data\FindingStatus;
use Beacon\Data\Observation;
use Beacon\Data\Severity;

/**
 * One deterministic route check.
 *
 * Implementations decide from a {@see RouteCatalog} only. They do not read
 * the router, dispatch requests, or resolve controllers.
 */
abstract class RouteCheck
{
    abstract public function evaluate(RouteCatalog $catalog): Finding;

    /**
     * @param list<FindingEvidence> $evidence
     */
    final protected function finding(
        string $id,
        FindingStatus $status,
        Severity $severity,
        string $title,
        string $description,
        array $evidence,
        ?string $remediation,
    ): Finding {
        return new Finding($id, $status, $severity, $title, $description, $evidence, $remediation);
    }

    final protected function evidence(string $name, Observation $observation): FindingEvidence
    {
        return new FindingEvidence($name, $observation);
    }
}
