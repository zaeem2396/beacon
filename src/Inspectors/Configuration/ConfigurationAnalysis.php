<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Configuration;

use Beacon\Data\DataObject;
use Beacon\Data\Finding;

/**
 * Findings produced from a configuration catalog. Pagination is applied later.
 */
final readonly class ConfigurationAnalysis extends DataObject
{
    /**
     * @param list<Finding> $findings
     */
    public function __construct(
        public array $findings,
    ) {
        foreach ($findings as $finding) {
            if (! $finding instanceof Finding) {
                throw new \InvalidArgumentException('Configuration analysis findings must be findings.');
            }
        }
    }
}
