<?php

declare(strict_types=1);

namespace Beacon\Schema;

use Beacon\Data\SchemaObject;
use Beacon\Database\DatabaseVersion;
use Beacon\Health\LaravelDatabaseProbe;
use Beacon\Health\SafeName;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;

/**
 * Reads MySQL and MariaDB metadata from information_schema.
 *
 * Every statement is fixed and checked before it runs. The connection is
 * made read-only for the session and is not registered on the database manager.
 */
final class InformationSchemaReader implements SchemaReader
{
    public const DISCOVERY_LIMIT = 2000;

    private const TABLES = <<<'SQL'
        select table_name as name, table_rows as row_estimate
        from information_schema.tables
        where table_schema = database()
        and table_type in ('BASE TABLE', 'SYSTEM VERSIONED')
        SQL;

    private const VIEWS = <<<'SQL'
        select table_name as name
        from information_schema.views
        where table_schema = database()
        SQL;

    private const COLUMNS = <<<'SQL'
        select table_name, column_name, ordinal_position, data_type, column_type, is_nullable,
        column_default, character_maximum_length, numeric_precision, numeric_scale, extra,
        generation_expression
        from information_schema.columns
        where table_schema = database() and table_name in (%s)
        order by table_name, ordinal_position
        SQL;

    private const INDEXES = <<<'SQL'
        select table_name, index_name, seq_in_index, column_name, non_unique, index_type
        from information_schema.statistics
        where table_schema = database() and table_name in (%s)
        order by table_name, index_name, seq_in_index
        SQL;

    private const FOREIGN_KEYS = <<<'SQL'
        select kc.table_name, kc.constraint_name, kc.ordinal_position, kc.column_name,
        kc.referenced_table_name, kc.referenced_column_name, rc.update_rule, rc.delete_rule
        from information_schema.key_column_usage kc
        inner join information_schema.referential_constraints rc
        on kc.constraint_schema = rc.constraint_schema
        and kc.table_name = rc.table_name
        and kc.constraint_name = rc.constraint_name
        where kc.table_schema = database()
        and kc.referenced_table_name is not null
        and kc.table_name in (%s)
        order by kc.table_name, kc.constraint_name, kc.ordinal_position
        SQL;

    public function __construct(
        private readonly ConnectionFactory $factory,
        private readonly SchemaAssembler $assembler,
    ) {
    }

    public function read(array $config, int $timeoutSeconds, SchemaQuery $query, array $exclude): SchemaReadResult
    {
        $driver = $config['driver'] ?? null;
        $driver = is_string($driver) ? strtolower($driver) : '';

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new \RuntimeException('Schema inspection does not open this driver.');
        }

        $connection = $this->factory->make(LaravelDatabaseProbe::prepare($config, $timeoutSeconds), 'beacon-database-schema');

        try {
            $this->run($connection, 'set session transaction read only');
            $identity = $this->select($connection, 'select version() as beacon_version, database() as beacon_database');
            $server = $identity[0] ?? [];
            [$rules, $ignored] = $this->rules($exclude);
            $tables = $this->discover($connection, self::TABLES, $query->table);
            $views = $query->includeViews ? $this->discover($connection, self::VIEWS, $query->table) : ['rows' => [], 'truncated' => false];
            $catalog = $this->catalog($tables['rows'], $views['rows'], $rules, $query->table !== null);
            $offset = ($query->page - 1) * $query->limit;
            $page = array_slice($catalog['objects'], $offset, $query->limit);
            $objects = $this->details($connection, $page, $query);
            $estimates = false;

            foreach ($catalog['objects'] as $object) {
                if ($object['row_estimate'] !== null) {
                    $estimates = true;

                    break;
                }
            }

            return new SchemaReadResult(
                $this->database($server['beacon_database'] ?? null),
                DatabaseVersion::sanitize(is_string($server['beacon_version'] ?? null) || is_numeric($server['beacon_version'] ?? null) ? (string) $server['beacon_version'] : ''),
                $catalog['tables'],
                $catalog['views'],
                $objects['objects'],
                $tables['truncated'] || $views['truncated'],
                $catalog['excluded'],
                $catalog['withheld'] || $objects['withheld'],
                $ignored,
                $estimates,
            );
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * @param list<string> $exclude
     *
     * @return array{0: list<string>, 1: bool}
     */
    private function rules(array $exclude): array
    {
        $rules = [];
        $ignored = false;

        foreach ($exclude as $rule) {
            if (! is_string($rule)) {
                $ignored = true;

                continue;
            }

            $rule = trim($rule);

            if ($rule === '') {
                continue;
            }

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}\*?$/', $rule) !== 1) {
                $ignored = true;

                continue;
            }

            $rules[] = $rule;
        }

        return [$rules, $ignored];
    }

    /**
     * @return array{rows: list<array{name: string, row_estimate: int|null}>, truncated: bool}
     */
    private function discover(Connection $connection, string $sql, ?string $table): array
    {
        $limit = $table === null ? self::DISCOVERY_LIMIT + 1 : 2;
        $statement = $sql . ($table === null ? '' : ' and table_name = ?') . ' order by table_name limit ' . $limit;
        $rows = $this->select($connection, $statement, $table === null ? [] : [$table]);
        $truncated = $table === null && count($rows) > self::DISCOVERY_LIMIT;

        if ($truncated) {
            array_pop($rows);
        }

        $named = [];

        foreach ($rows as $row) {
            $name = $row['name'] ?? null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            $named[] = [
                'name' => $name,
                'row_estimate' => $this->estimate($row['row_estimate'] ?? null),
            ];
        }

        return ['rows' => $named, 'truncated' => $truncated];
    }

    /**
     * @param list<array{name: string, row_estimate: int|null}> $tables
     * @param list<array{name: string, row_estimate: int|null}> $views
     * @param list<string> $rules
     *
     * @return array{objects: list<array{name: string, type: ObjectType, row_estimate: int|null}>, tables: int, views: int, excluded: bool, withheld: bool}
     */
    private function catalog(array $tables, array $views, array $rules, bool $filtered): array
    {
        $objects = [];
        $excluded = false;
        $withheld = false;

        foreach ($tables as $table) {
            $kept = $this->keep($table['name'], $rules, $filtered, $excluded, $withheld);

            if ($kept) {
                $objects[] = ['name' => $table['name'], 'type' => ObjectType::Table, 'row_estimate' => $table['row_estimate']];
            }
        }

        foreach ($views as $view) {
            $kept = $this->keep($view['name'], $rules, $filtered, $excluded, $withheld);

            if ($kept) {
                $objects[] = ['name' => $view['name'], 'type' => ObjectType::View, 'row_estimate' => null];
            }
        }

        usort($objects, static function (array $left, array $right): int {
            $type = ($left['type'] === ObjectType::Table ? 0 : 1) <=> ($right['type'] === ObjectType::Table ? 0 : 1);

            return $type !== 0 ? $type : strcmp($left['name'], $right['name']);
        });

        $tableCount = 0;
        $viewCount = 0;

        foreach ($objects as $object) {
            if ($object['type'] === ObjectType::Table) {
                $tableCount++;
            } else {
                $viewCount++;
            }
        }

        return [
            'objects' => $objects,
            'tables' => $tableCount,
            'views' => $viewCount,
            'excluded' => $filtered ? false : $excluded,
            'withheld' => $withheld,
        ];
    }

    /**
     * @param list<string> $rules
     */
    private function keep(string $name, array $rules, bool $filtered, bool &$excluded, bool &$withheld): bool
    {
        if (! SafeName::isIdentifier($name)) {
            $withheld = true;

            return false;
        }

        foreach ($rules as $rule) {
            $prefix = str_ends_with($rule, '*') ? substr($rule, 0, -1) : null;
            $match = $prefix === null ? $rule === $name : $prefix !== '' && str_starts_with($name, $prefix);

            if ($match) {
                if (! $filtered) {
                    $excluded = true;
                }

                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array{name: string, type: ObjectType, row_estimate: int|null}> $page
     *
     * @return array{objects: list<SchemaObject>, withheld: bool}
     */
    private function details(Connection $connection, array $page, SchemaQuery $query): array
    {
        if ($page === []) {
            return ['objects' => [], 'withheld' => false];
        }

        $names = array_map(static fn (array $object): string => $object['name'], $page);
        $placeholders = implode(', ', array_fill(0, count($names), '?'));
        $columns = MetadataGroups::columnsByTable($this->select($connection, sprintf(self::COLUMNS, $placeholders), $names));
        $indexes = MetadataGroups::indexesByTable($this->select($connection, sprintf(self::INDEXES, $placeholders), $names));
        $foreignKeys = MetadataGroups::foreignKeysByTable($this->select($connection, sprintf(self::FOREIGN_KEYS, $placeholders), $names));
        $objects = [];
        $withheld = false;

        foreach ($page as $object) {
            $assembled = $this->assembler->assemble(
                $object,
                $columns[$object['name']] ?? [],
                $indexes[$object['name']] ?? [],
                $foreignKeys[$object['name']] ?? [],
                $query,
            );

            if ($assembled->object === null) {
                $withheld = true;

                continue;
            }

            $objects[] = $assembled->object;
            $withheld = $withheld || $assembled->withheld;
        }

        return ['objects' => $objects, 'withheld' => $withheld];
    }

    /**
     * @param list<mixed> $bindings
     *
     * @return list<array<string, mixed>>
     */
    private function select(Connection $connection, string $sql, array $bindings = []): array
    {
        $rows = [];

        foreach ($this->run($connection, $sql, $bindings) as $row) {
            if (! is_object($row)) {
                continue;
            }

            $fields = [];

            foreach (get_object_vars($row) as $key => $value) {
                $fields[strtolower((string) $key)] = $value;
            }

            $rows[] = $fields;
        }

        return $rows;
    }

    /**
     * @param list<mixed> $bindings
     *
     * @return list<mixed>
     */
    private function run(Connection $connection, string $sql, array $bindings = []): array
    {
        if (! SchemaSql::isReadOnly($sql)) {
            throw new \RuntimeException('Schema inspection refused a statement that is not read-only.');
        }

        if (str_starts_with(strtolower(ltrim($sql)), 'select')) {
            return array_values($connection->select($sql, $bindings));
        }

        $connection->statement($sql);

        return [];
    }

    private function database(mixed $value): ?string
    {
        if (! is_string($value) || ! SafeName::isIdentifier($value)) {
            return null;
        }

        return $value;
    }

    private function estimate(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d{1,15}$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
