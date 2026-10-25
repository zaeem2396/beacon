<?php

declare(strict_types=1);

namespace Beacon\Health;

use Beacon\Contracts\HealthCheck;
use Beacon\Data\HealthCheckResult;
use Beacon\Data\HealthCounts;
use Beacon\Data\HealthInspection;

/**
 * Runs each check independently and builds the report.
 *
 * A check that throws, or returns another check's name, becomes an unknown
 * result. Later checks still run. The internal error is not copied.
 */
final class HealthRunner
{
    /**
     * @param list<HealthCheck> $checks
     */
    public function __construct(
        private readonly array $checks,
        private readonly Clock $clock,
    ) {
        foreach ($checks as $check) {
            if (! $check instanceof HealthCheck) {
                throw new \InvalidArgumentException('Health runner checks must be health checks.');
            }
        }
    }

    public function run(): HealthInspection
    {
        $results = [];

        foreach ($this->checks as $check) {
            $results[] = $this->execute($check);
        }

        $counts = HealthCounts::fromChecks($results);
        $assessedAt = $this->clock->now()
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\+00:00');

        return new HealthInspection($counts->overall(), $counts->summary(), $assessedAt, $counts, $results);
    }

    private function execute(HealthCheck $check): HealthCheckResult
    {
        $started = hrtime(true);

        try {
            $name = $check->name();
            $description = $check->description();
            $result = $check->check();

            if ($result->name !== $name) {
                return $this->unavailable($name, $description, $started);
            }

            return $result->withDuration($this->milliseconds($started));
        } catch (\Throwable) {
            return $this->unavailable($this->fallbackName($check), $this->fallbackDescription($check), $started);
        }
    }

    private function fallbackName(HealthCheck $check): string
    {
        try {
            $name = $check->name();
        } catch (\Throwable) {
            return 'health_check';
        }

        return preg_match('/^[a-z][a-z0-9_]{0,40}$/', $name) === 1 ? $name : 'health_check';
    }

    private function fallbackDescription(HealthCheck $check): string
    {
        try {
            $description = $check->description();
        } catch (\Throwable) {
            return 'A health check.';
        }

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9 .,_-]{0,239}$/', $description) === 1
            ? $description
            : 'A health check.';
    }

    private function unavailable(string $name, string $description, int $started): HealthCheckResult
    {
        return new HealthCheckResult(
            $name,
            $description,
            HealthStatus::Unknown,
            'The check could not be completed.',
            $this->milliseconds($started),
            'Run the check again. Beacon did not include the internal error.',
            [],
        );
    }

    private function milliseconds(int $started): int
    {
        $elapsed = (int) round((hrtime(true) - $started) / 1_000_000);

        return max(0, min(120000, $elapsed));
    }
}
