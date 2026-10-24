<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Routes;

use Beacon\Data\DataObject;
use Beacon\Data\Finding;

/**
 * Findings produced from a route catalog. Pagination is applied later.
 */
final readonly class RouteAnalysis extends DataObject
{
    /**
     * @param list<Finding> $findings
     */
    public function __construct(
        public array $findings,
    ) {
        foreach ($findings as $finding) {
            if (! $finding instanceof Finding) {
                throw new \InvalidArgumentException('Route analysis findings must be findings.');
            }
        }
    }
}
