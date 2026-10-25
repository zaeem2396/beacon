<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Health\HealthStatus;

/**
 * A point-in-time health report.
 *
 * The status, summary, and counts are derived from the checks. A caller cannot
 * assemble a healthy report that contains a critical check.
 */
final readonly class HealthInspection extends DataObject
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    /**
     * @param list<HealthCheckResult> $checks
     */
    public function __construct(
        public HealthStatus $status,
        public string $summary,
        public string $assessedAt,
        public HealthCounts $counts,
        public array $checks,
    ) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $assessedAt) !== 1) {
            throw new \InvalidArgumentException('Health assessment time must be a UTC timestamp.');
        }

        $expected = HealthCounts::fromChecks($checks);

        if ($counts->toArray() !== $expected->toArray()) {
            throw new \InvalidArgumentException('Health counts do not match the checks.');
        }

        if ($status !== $expected->overall() || $summary !== $expected->summary()) {
            throw new \InvalidArgumentException('Health status does not match the checks.');
        }

        $this->schemaVersion = self::SCHEMA_VERSION;
    }
}
