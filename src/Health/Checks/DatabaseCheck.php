<?php

declare(strict_types=1);

namespace Beacon\Health\Checks;

use Beacon\Contracts\HealthCheck;
use Beacon\Core\BeaconConfig;
use Beacon\Data\FindingEvidence;
use Beacon\Data\HealthCheckResult;
use Beacon\Data\Observation;
use Beacon\Health\DatabaseProbe;
use Beacon\Health\HealthStatus;
use Beacon\Health\SafeName;
use Illuminate\Contracts\Config\Repository;

/**
 * Optionally probes the default database connection with `select 1`.
 *
 * The probe is off unless beacon.health.database is enabled. It does not
 * migrate, write, or include the driver error or credentials in the result.
 */
final class DatabaseCheck implements HealthCheck
{
    public const NAME = 'database';

    public const DESCRIPTION = 'A read-only connectivity probe of the default database connection, when enabled.';

    public function __construct(
        private readonly BeaconConfig $config,
        private readonly Repository $repository,
        private readonly DatabaseProbe $probe,
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
        if (! $this->config->healthDatabaseEnabled()) {
            return $this->result(
                HealthStatus::Skipped,
                'The database probe is disabled.',
                'Set BEACON_HEALTH_DATABASE to true to run a read-only connectivity probe.',
                Observation::Disabled,
            );
        }

        $timeout = $this->config->healthDatabaseTimeout();

        if ($timeout === null) {
            return $this->result(
                HealthStatus::Unknown,
                'The database probe timeout must be an integer from 1 to 5 seconds.',
                'Set the database probe timeout to an integer from 1 to 5.',
                Observation::Invalid,
            );
        }

        $name = $this->repository->get('database.default');

        if (! is_string($name) || $name === '' || ! SafeName::isIdentifier($name)) {
            return $this->result(
                HealthStatus::Unknown,
                'The default database connection could not be read safely.',
                'Set the default database connection to a short name without dots.',
                Observation::Invalid,
            );
        }

        $connection = $this->repository->get('database.connections.' . $name);

        if (! is_array($connection)) {
            return $this->result(
                HealthStatus::Unknown,
                'The default database connection is not registered.',
                'Register the default database connection before enabling the probe.',
                Observation::Missing,
            );
        }

        $config = [];

        foreach ($connection as $key => $value) {
            if (! is_string($key)) {
                return $this->result(
                    HealthStatus::Unknown,
                    'The default database connection could not be read safely.',
                    'Set the default database connection to a short name without dots.',
                    Observation::Invalid,
                );
            }

            $config[$key] = $value;
        }

        if (! $this->sqliteIsReadable($config)) {
            return $this->result(
                HealthStatus::Unknown,
                'The sqlite database is not available for a read-only probe.',
                'Use an in-memory sqlite database or an existing database file before enabling the probe.',
                Observation::Missing,
            );
        }

        try {
            $this->probe->selectOne($config, $timeout);
        } catch (\Throwable) {
            return $this->result(
                HealthStatus::Critical,
                'The default database connection did not answer a read-only probe.',
                'Check that the default database accepts connections. Beacon does not include the driver error.',
                Observation::Invalid,
            );
        }

        return $this->result(
            HealthStatus::Healthy,
            'The default database connection answered a read-only probe.',
            null,
            Observation::Enabled,
        );
    }

    /**
     * A missing sqlite file is not created. In-memory sqlite and other
     * drivers are left for the probe.
     *
     * @param array<string, mixed> $config
     */
    private function sqliteIsReadable(array $config): bool
    {
        $driver = $config['driver'] ?? null;

        if (! is_string($driver) || strtolower($driver) !== 'sqlite') {
            return true;
        }

        $database = $config['database'] ?? null;

        if ($database === ':memory:') {
            return true;
        }

        return is_string($database) && $database !== '' && is_file($database);
    }

    private function result(HealthStatus $status, string $summary, ?string $remediation, Observation $observation): HealthCheckResult
    {
        return new HealthCheckResult(self::NAME, self::DESCRIPTION, $status, $summary, null, $remediation, [
            new FindingEvidence('database.probe', $observation),
        ]);
    }
}
