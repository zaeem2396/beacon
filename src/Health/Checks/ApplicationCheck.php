<?php

declare(strict_types=1);

namespace Beacon\Health\Checks;

use Beacon\Contracts\HealthCheck;
use Beacon\Data\FindingEvidence;
use Beacon\Data\HealthCheckResult;
use Beacon\Data\Observation;
use Beacon\Health\HealthStatus;
use Illuminate\Foundation\Application;

/**
 * Confirms this process can read the booted Laravel application.
 *
 * Maintenance mode is degraded, not a crash: the application is up and is
 * refusing normal traffic. Version strings are not treated as secrets.
 */
final class ApplicationCheck implements HealthCheck
{
    public const NAME = 'application';

    public const DESCRIPTION = 'Whether this process has a booted Laravel application and readable version metadata.';

    public function __construct(
        private readonly Application $app,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return self::DESCRIPTION;
    }

    public function check(): HealthCheckResult
    {
        if (! $this->app->isBooted()) {
            return $this->result(
                HealthStatus::Critical,
                'The Laravel application is not booted.',
                'Boot the application before requesting health.',
                Observation::Missing,
            );
        }

        $version = $this->app->version();
        $environment = $this->app->environment();
        $maintenance = $this->app->isDownForMaintenance();

        if (! is_string($version) || $version === '' || ! is_string($environment) || ! is_bool($maintenance)) {
            return $this->result(
                HealthStatus::Unknown,
                'The application version or maintenance state could not be read.',
                'Expose a Laravel version string and a boolean maintenance state.',
                Observation::Invalid,
            );
        }

        if ($maintenance) {
            return $this->result(
                HealthStatus::Warning,
                'The application is booted and is in maintenance mode.',
                'Leave maintenance mode when the application should serve traffic.',
                Observation::Enabled,
            );
        }

        return $this->result(
            HealthStatus::Healthy,
            'The application is booted and is not in maintenance mode.',
            null,
            Observation::Disabled,
        );
    }

    private function result(HealthStatus $status, string $summary, ?string $remediation, Observation $maintenance): HealthCheckResult
    {
        return new HealthCheckResult(self::NAME, self::DESCRIPTION, $status, $summary, null, $remediation, [
            new FindingEvidence('application.maintenance', $maintenance),
        ]);
    }
}
