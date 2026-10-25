<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Health\HealthStatus;

/**
 * How many checks landed in each status, and the report status that follows.
 *
 * A critical check makes the report critical. Otherwise a warning makes it
 * a warning, and an unknown check makes it unknown. Skipped checks are not
 * passes: they do not increase the healthy count. The report is healthy only
 * when at least one check passed and none are critical, warning, or unknown.
 * A report whose checks were all skipped is skipped. An empty report is unknown.
 */
final readonly class HealthCounts extends DataObject
{
    public function __construct(
        public int $total,
        public int $healthy,
        public int $warning,
        public int $critical,
        public int $unknown,
        public int $skipped,
    ) {
        foreach ([$total, $healthy, $warning, $critical, $unknown, $skipped] as $count) {
            if ($count < 0) {
                throw new \InvalidArgumentException('Health counts cannot be negative.');
            }
        }

        if ($healthy + $warning + $critical + $unknown + $skipped !== $total) {
            throw new \InvalidArgumentException('Health counts must add up to the total.');
        }
    }

    /**
     * @param list<HealthCheckResult> $checks
     */
    public static function fromChecks(array $checks): self
    {
        $healthy = 0;
        $warning = 0;
        $critical = 0;
        $unknown = 0;
        $skipped = 0;

        foreach ($checks as $check) {
            if (! $check instanceof HealthCheckResult) {
                throw new \InvalidArgumentException('Health counts require health check results.');
            }

            match ($check->status) {
                HealthStatus::Healthy => $healthy++,
                HealthStatus::Warning => $warning++,
                HealthStatus::Critical => $critical++,
                HealthStatus::Unknown => $unknown++,
                HealthStatus::Skipped => $skipped++,
            };
        }

        return new self(count($checks), $healthy, $warning, $critical, $unknown, $skipped);
    }

    public function overall(): HealthStatus
    {
        if ($this->critical > 0) {
            return HealthStatus::Critical;
        }

        if ($this->warning > 0) {
            return HealthStatus::Warning;
        }

        if ($this->unknown > 0 || $this->total === 0) {
            return HealthStatus::Unknown;
        }

        if ($this->healthy > 0) {
            return HealthStatus::Healthy;
        }

        return HealthStatus::Skipped;
    }

    public function summary(): string
    {
        return match ($this->overall()) {
            HealthStatus::Critical => 'A health check reported a confirmed failure.',
            HealthStatus::Warning => 'A health check reported a degraded condition.',
            HealthStatus::Unknown => $this->total === 0
                ? 'No health checks were registered.'
                : 'A health check could not determine health.',
            HealthStatus::Healthy => 'Every completed health check passed.',
            HealthStatus::Skipped => 'Every health check was skipped.',
        };
    }
}
