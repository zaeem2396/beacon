<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Schema\Prose;

/**
 * One page of migration risk findings.
 *
 * Findings are heuristic static analysis. They are not a prediction that a
 * migration will succeed, fail, lock, or lose data.
 */
final readonly class MigrationRiskInspection extends DataObject
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    /**
     * @param list<RiskFinding> $findings
     * @param list<RiskLimitation> $limitations
     * @param list<string> $warnings
     */
    public function __construct(
        public MigrationConnection $connection,
        public MigrationRepositoryInfo $repository,
        public RiskSummary $summary,
        public RiskScope $scope,
        public MigrationPage $pagination,
        public array $findings,
        public array $limitations,
        public array $warnings,
    ) {
        if ($pagination->count !== count($findings)) {
            throw new \InvalidArgumentException('Risk pagination count must match the returned findings.');
        }

        foreach ($findings as $finding) {
            if (! $finding instanceof RiskFinding) {
                throw new \InvalidArgumentException('Risk inspection findings must be risk findings.');
            }
        }

        if (count($limitations) > 12) {
            throw new \InvalidArgumentException('Risk inspection has too many limitations.');
        }

        foreach ($limitations as $limitation) {
            if (! $limitation instanceof RiskLimitation) {
                throw new \InvalidArgumentException('Risk inspection limitations must be limitations.');
            }
        }

        if (count($warnings) > 8) {
            throw new \InvalidArgumentException('Risk inspection has too many warnings.');
        }

        foreach ($warnings as $warning) {
            if (! is_string($warning) || ! Prose::isSafe($warning)) {
                throw new \InvalidArgumentException('Risk warnings must be short prose.');
            }
        }

        $this->schemaVersion = self::SCHEMA_VERSION;
    }
}
