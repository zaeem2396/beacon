<?php

declare(strict_types=1);

namespace Beacon\Risk;

use Beacon\Core\BeaconConfig;
use Beacon\Data\SchemaColumn;
use Beacon\Data\SchemaInspection;
use Beacon\Data\SchemaObject;
use Beacon\Database\ConnectionStatus;
use Beacon\Health\SafeName;
use Beacon\Inspectors\SchemaInspector;
use Beacon\Security\InspectionCapability;

/**
 * Loads table metadata through schema inspection when that capability is allowed.
 *
 * It does not read table rows and it does not run migration SQL.
 */
final class SchemaContext
{
    public const TABLE_LIMIT = 20;

    public function __construct(
        private readonly SchemaInspector $schemas,
        private readonly BeaconConfig $config,
    ) {
    }

    /**
     * @param list<string> $tables
     */
    public function lookup(?string $connection, array $tables, bool $requested): SchemaLookup
    {
        if (! $requested || ! $this->permitted() || $connection === null || $tables === []) {
            return SchemaLookup::unused();
        }

        $names = [];

        foreach ($tables as $table) {
            if (SafeName::isIdentifier($table) && ! in_array($table, $names, true)) {
                $names[] = $table;
            }
        }

        sort($names);
        $truncated = count($names) > self::TABLE_LIMIT;

        if ($truncated) {
            $names = array_slice($names, 0, self::TABLE_LIMIT);
        }

        $snapshots = [];

        foreach ($names as $name) {
            try {
                $inspection = $this->schemas->inspect([
                    'connection' => $connection,
                    'table' => $name,
                    'include_views' => false,
                    'include_columns' => true,
                    'include_indexes' => true,
                    'include_foreign_keys' => true,
                    'page' => 1,
                    'limit' => 1,
                ]);
            } catch (\Throwable) {
                return new SchemaLookup(false, true, $truncated, []);
            }

            if (! $inspection instanceof SchemaInspection || $inspection->connection->status !== ConnectionStatus::Connected) {
                return new SchemaLookup(false, true, $truncated, []);
            }

            $snapshot = $this->snapshot($name, $inspection->objects[0] ?? null);

            if ($snapshot instanceof TableSnapshot) {
                $snapshots[$name] = $snapshot;
            }
        }

        return new SchemaLookup(true, false, $truncated, $snapshots);
    }

    public function permitted(): bool
    {
        $allowed = $this->config->allowedCapabilities();

        return in_array('*', $allowed, true) || in_array(InspectionCapability::Schema->value, $allowed, true);
    }

    private function snapshot(string $name, mixed $object): ?TableSnapshot
    {
        if (! $object instanceof SchemaObject || $object->name !== $name || $object->type->value !== 'table') {
            return null;
        }

        $columns = [];

        foreach ($object->columns as $column) {
            if ($column instanceof SchemaColumn) {
                $columns[$column->name] = new ColumnSnapshot(
                    $column->nullable,
                    $column->characterLength,
                    $column->numericPrecision,
                    $column->numericScale,
                    $column->typeName,
                );
            }
        }

        return new TableSnapshot(true, $object->rowEstimate, $columns);
    }
}
