<?php

declare(strict_types=1);

namespace Beacon\Inspectors;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Core\BeaconConfig;
use Beacon\Data\DatabaseStatus;
use Beacon\Data\SchemaConnection;
use Beacon\Data\SchemaInspection;
use Beacon\Data\SchemaPage;
use Beacon\Data\SchemaSummary;
use Beacon\Database\ConnectionStatus;
use Beacon\Health\Clock;
use Beacon\Health\SafeName;
use Beacon\Schema\SchemaQuery;
use Beacon\Schema\SchemaReader;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;
use Illuminate\Contracts\Config\Repository;

/**
 * Reports schema metadata for an allowed connection.
 *
 * MySQL and MariaDB are read through information_schema. Other drivers are
 * identified and not opened. The caller cannot supply SQL or connection settings.
 *
 * @implements Inspector<SchemaInspection>
 */
final class SchemaInspector implements Inspector
{
    public const NAME = 'schema';

    public function __construct(
        private readonly BeaconConfig $config,
        private readonly Repository $repository,
        private readonly SchemaReader $reader,
        private readonly Clock $clock,
    ) {
    }

    public function capability(): Capability
    {
        return InspectionCapability::Schema->capability();
    }

    public function inspect(array $parameters = []): Data
    {
        $query = SchemaQuery::from($parameters);
        $inspectedAt = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\+00:00');
        $timeout = $this->config->schemaTimeout();

        if ($timeout === null) {
            return $this->empty($query, null, null, ConnectionStatus::Unknown, $inspectedAt, 'The schema inspection timeout must be an integer from 1 to 5 seconds.');
        }

        $default = $this->repository->get('database.default');
        $allowed = $this->allowed(is_string($default) ? $default : null);
        $name = $query->connection ?? (is_string($default) ? $default : null);

        if (! is_string($name) || ! SafeName::isIdentifier($name) || ! in_array($name, $allowed, true)) {
            $summary = is_string($name) && SafeName::isIdentifier($name)
                ? 'The requested database connection is not allowed for schema inspection.'
                : 'The database connection could not be read safely.';

            return $this->empty($query, null, null, ConnectionStatus::Unknown, $inspectedAt, $summary);
        }

        $definition = $this->repository->get('database.connections.' . $name);

        if (! is_array($definition)) {
            return $this->empty($query, $name, null, ConnectionStatus::Unknown, $inspectedAt, 'The database connection is not registered.');
        }

        $connection = [];

        foreach ($definition as $key => $value) {
            if (! is_string($key)) {
                return $this->empty($query, $name, null, ConnectionStatus::Unknown, $inspectedAt, 'The database connection could not be read safely.');
            }

            $connection[$key] = $value;
        }

        $driver = $connection['driver'] ?? null;
        $driver = is_string($driver) ? strtolower($driver) : null;

        if (! is_string($driver) || ! in_array($driver, DatabaseStatus::DRIVERS, true)) {
            return $this->empty($query, $name, null, ConnectionStatus::Unknown, $inspectedAt, 'The database driver could not be read safely.');
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return $this->empty($query, $name, $driver, ConnectionStatus::Unsupported, $inspectedAt, 'This database driver is recognised, but this release does not open it.');
        }

        try {
            $read = $this->reader->read($connection, $timeout, $query, $this->config->schemaExclusions());
        } catch (\Throwable) {
            return $this->empty($query, $name, $driver, ConnectionStatus::Unavailable, $inspectedAt, 'The database schema could not be read.');
        }

        $total = $read->tableCount + $read->viewCount;
        $warnings = [];

        if ($read->truncated) {
            $warnings[] = 'Schema discovery stopped at the object limit.';
        }

        if ($read->excluded) {
            $warnings[] = 'Configured exclusions removed one or more objects.';
        }

        if ($read->withheld) {
            $warnings[] = 'An object or column name was withheld because it is not a plain identifier.';
        }

        if ($read->ignoredRule) {
            $warnings[] = 'A schema exclusion rule was ignored.';
        }

        if ($read->estimates) {
            $warnings[] = 'Row counts are estimates from database metadata.';
        }

        return new SchemaInspection(
            new SchemaConnection($name, $driver, $read->database, $read->version, ConnectionStatus::Connected),
            new SchemaSummary($read->tableCount, $read->viewCount, $inspectedAt, 'The database schema was read from metadata.'),
            new SchemaPage($query->page, $query->limit, $total, $total === 0 ? 0 : (int) ceil($total / $query->limit), count($read->objects)),
            $read->objects,
            $warnings,
        );
    }

    /**
     * @return list<string>
     */
    private function allowed(?string $default): array
    {
        $allowed = [];

        if (is_string($default) && SafeName::isIdentifier($default)) {
            $allowed[] = $default;
        }

        foreach ($this->config->schemaConnections() as $name) {
            if (SafeName::isIdentifier($name) && ! in_array($name, $allowed, true)) {
                $allowed[] = $name;
            }
        }

        return $allowed;
    }

    private function empty(SchemaQuery $query, ?string $name, ?string $driver, ConnectionStatus $status, string $inspectedAt, string $summary): SchemaInspection
    {
        return new SchemaInspection(
            new SchemaConnection($name, $driver, null, null, $status),
            new SchemaSummary(0, 0, $inspectedAt, $summary),
            new SchemaPage($query->page, $query->limit, 0, 0, 0),
            [],
            [],
        );
    }
}
