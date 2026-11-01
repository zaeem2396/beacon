<?php

declare(strict_types=1);

namespace Beacon\Risk;

use Beacon\Data\RiskFinding;
use Beacon\Data\RiskLimitation;

/**
 * Findings for the selected migration files.
 */
final readonly class RiskEngineResult
{
    /**
     * @param list<RiskFinding> $findings
     * @param list<RiskLimitation> $limitations
     */
    public function __construct(
        public array $findings,
        public array $limitations,
        public int $analyzed,
        public bool $findingsTruncated,
        public bool $schemaApplied,
        public bool $schemaFailed,
        public bool $schemaTruncated,
    ) {
    }
}
