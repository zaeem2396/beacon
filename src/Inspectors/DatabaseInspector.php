<?php

declare(strict_types=1);

namespace Beacon\Inspectors;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Core\BeaconConfig;
use Beacon\Data\DatabaseStatus;
use Beacon\Database\ConnectionStatus;
use Beacon\Database\DatabaseVersion;
use Beacon\Database\StatusProbe;
use Beacon\Exceptions\InvalidDatabaseQueryException;
use Beacon\Health\SafeName;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;
use Illuminate\Contracts\Config\Repository;

/**
 * Reports the default database connection without credentials.
 *
 * MySQL, MariaDB, and SQLite are probed with fixed read-only statements.
 * PostgreSQL and SQL Server are identified and not opened. The caller cannot
 * choose a connection, a timeout, or a statement.
 *
 * @implements Inspector<DatabaseStatus>
 */
final class DatabaseInspector implements Inspector
{
    public const NAME = 'database';

    public function __construct(
        private readonly BeaconConfig $config,
        private readonly Repository $repository,
        private readonly StatusProbe $probe,
    ) {
    }

    public function capability(): Capability
    {
        return InspectionCapability::Database->capability();
    }

    public function inspect(array $parameters = []): Data
    {
        if ($parameters !== []) {
            throw InvalidDatabaseQueryException::arguments();
        }

        $timeout = $this->config->databaseTimeout();

        if ($timeout === null) {
            return $this->status(null, null, ConnectionStatus::Unknown, null, null, 'The database status timeout must be an integer from 1 to 5 seconds.');
        }

        $name = $this->repository->get('database.default');

        if (! is_string($name) || $name === '' || ! SafeName::isIdentifier($name)) {
            return $this->status(null, null, ConnectionStatus::Unknown, null, null, 'The default database connection could not be read safely.');
        }

        $definition = $this->repository->get('database.connections.' . $name);

        if (! is_array($definition)) {
            return $this->status($name, null, ConnectionStatus::Unknown, null, null, 'The default database connection is not registered.');
        }

        $connection = [];

        foreach ($definition as $key => $value) {
            if (! is_string($key)) {
                return $this->status($name, null, ConnectionStatus::Unknown, null, null, 'The default database connection could not be read safely.');
            }

            $connection[$key] = $value;
        }

        $driver = $connection['driver'] ?? null;
        $driver = is_string($driver) ? strtolower($driver) : null;

        if (! is_string($driver) || ! in_array($driver, DatabaseStatus::DRIVERS, true)) {
            return $this->status($name, null, ConnectionStatus::Unknown, null, null, 'The database driver could not be read safely.');
        }

        if (! in_array($driver, DatabaseStatus::PROBED_DRIVERS, true)) {
            return $this->status($name, $driver, ConnectionStatus::Unsupported, null, null, 'This database driver is recognised, but this release does not open it.');
        }

        if (! $this->sqliteIsReadable($connection)) {
            return $this->status($name, $driver, ConnectionStatus::Unknown, null, null, 'The sqlite database is not available for a read-only status probe.');
        }

        try {
            $measured = $this->probe->measure($connection, $timeout);
        } catch (\Throwable) {
            return $this->status($name, $driver, ConnectionStatus::Unavailable, null, null, 'The default database connection did not answer a read-only status probe.');
        }

        $version = DatabaseVersion::sanitize($measured->version);
        $summary = $version === null
            ? 'The default database connection answered, and the server version was not readable.'
            : 'The default database connection answered a read-only status probe.';

        return $this->status($name, $driver, ConnectionStatus::Connected, $version, $measured->latencyMs, $summary);
    }

    /**
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

    private function status(?string $connection, ?string $driver, ConnectionStatus $status, ?string $version, ?int $latencyMs, string $summary): DatabaseStatus
    {
        return new DatabaseStatus($connection, $driver, $status, $version, $latencyMs, $summary);
    }
}
