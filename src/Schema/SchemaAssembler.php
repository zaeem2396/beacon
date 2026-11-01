<?php

declare(strict_types=1);

namespace Beacon\Schema;

use Beacon\Data\SchemaColumn;
use Beacon\Data\SchemaForeignKey;
use Beacon\Data\SchemaIndex;
use Beacon\Data\SchemaObject;
use Beacon\Data\SchemaUniqueConstraint;
use Beacon\Health\SafeName;

/**
 * Turns grouped metadata into a schema object.
 *
 * Detail lists follow the request. Counts always describe the metadata that
 * was read. Unsafe names are dropped instead of being copied into the object.
 */
final class SchemaAssembler
{
    /**
     * @param array<string, mixed> $table
     * @param list<array<string, mixed>> $columns
     * @param list<array{name: string, columns: list<string>, unique: bool, primary: bool, type: string, expression: bool}> $indexes
     * @param list<array{name: string, columns: list<string>, referenced_table: string, referenced_columns: list<string>, on_update: string, on_delete: string}> $foreignKeys
     */
    public function assemble(array $table, array $columns, array $indexes, array $foreignKeys, SchemaQuery $query): AssembledObject
    {
        $name = $table['name'] ?? null;
        $type = $table['type'] ?? null;

        if (! is_string($name) || ! SafeName::isIdentifier($name) || ! $type instanceof ObjectType) {
            return new AssembledObject(null, true);
        }

        $withheld = false;
        $builtColumns = $this->columns($columns, $indexes, $withheld);
        $builtIndexes = $this->indexes($indexes, $withheld);
        $builtKeys = $this->foreignKeys($foreignKeys, $withheld);
        $primary = $this->primaryKey($builtIndexes);
        $builtColumns = $this->markPrimary($builtColumns, $primary);
        $unique = $this->uniqueConstraints($builtIndexes);
        $estimate = $type === ObjectType::Table && is_int($table['row_estimate'] ?? null) && $table['row_estimate'] >= 0
            ? $table['row_estimate']
            : null;

        return new AssembledObject(new SchemaObject(
            $name,
            $type,
            $estimate,
            $estimate !== null,
            count($builtColumns),
            $primary,
            count($builtIndexes),
            count($builtKeys),
            $query->includeColumns ? $builtColumns : [],
            $query->includeForeignKeys ? $builtKeys : [],
            $query->includeIndexes ? $builtIndexes : [],
            $query->includeIndexes ? $unique : [],
        ), $withheld);
    }

    /**
     * @param list<array<string, mixed>> $columns
     * @param list<array{name: string, columns: list<string>, unique: bool, primary: bool, type: string, expression: bool}> $indexes
     *
     * @return list<SchemaColumn>
     */
    private function columns(array $columns, array $indexes, bool &$withheld): array
    {
        $primary = $this->primaryKey($this->indexes($indexes, $withheld));
        $built = [];

        foreach ($columns as $column) {
            $record = $this->column($column, $primary);

            if ($record === null) {
                $withheld = true;

                continue;
            }

            $built[] = $record;
        }

        usort($built, static fn (SchemaColumn $left, SchemaColumn $right): int => $left->position <=> $right->position);

        return $built;
    }

    /**
     * @param array<string, mixed> $column
     * @param list<string> $primary
     */
    private function column(array $column, array $primary): ?SchemaColumn
    {
        $name = $column['name'] ?? null;
        $typeName = $column['type_name'] ?? null;

        if (! is_string($name) || ! SafeName::isIdentifier($name) || ! is_string($typeName) || preg_match('/^[a-z][a-z0-9_]{0,31}$/', $typeName) !== 1) {
            return null;
        }

        $full = is_string($column['column_type'] ?? null) ? $column['column_type'] : '';
        $columnType = preg_match('/^[a-z0-9(), ]{1,64}$/', $full) === 1 ? $full : null;
        $default = ColumnDefault::from($name, $column['default'] ?? null);
        $extra = strtolower(is_string($column['extra'] ?? null) ? $column['extra'] : '');
        $expression = is_string($column['generation_expression'] ?? null) && trim($column['generation_expression']) !== '';
        $generation = null;

        if ($expression && str_contains($extra, 'stored')) {
            $generation = 'stored';
        } elseif ($expression && str_contains($extra, 'virtual')) {
            $generation = 'virtual';
        }

        $precision = $this->bounded($column['numeric_precision'] ?? null, 1, 65);
        $scale = $this->bounded($column['numeric_scale'] ?? null, 0, 30);
        $numeric = in_array($typeName, ['decimal', 'numeric', 'float', 'double'], true);

        if (! $numeric) {
            $precision = null;
            $scale = null;
        } elseif ($scale !== null && $precision !== null && $scale > $precision) {
            $scale = null;
        }

        $length = in_array($typeName, ['char', 'varchar', 'binary', 'varbinary'], true)
            ? $this->bounded($column['character_length'] ?? null, 0, 16777216)
            : null;
        $position = $this->bounded($column['ordinal'] ?? null, 1, 4096);

        if ($position === null) {
            return null;
        }

        return new SchemaColumn(
            $name,
            $typeName,
            $columnType,
            ($column['nullable'] ?? false) === true,
            $default->present,
            $default->sensitive,
            $default->value,
            in_array($name, $primary, true),
            str_contains($extra, 'auto_increment'),
            $expression,
            $generation,
            $length,
            $precision,
            $scale,
            $columnType !== null && str_contains($columnType, 'unsigned'),
            $typeName === 'json',
            in_array($typeName, ['bool', 'boolean'], true) || ($columnType !== null && preg_match('/^tinyint\(1\)( unsigned)?$/', $columnType) === 1),
            $position,
        );
    }

    /**
     * @param list<array{name: string, columns: list<string>, unique: bool, primary: bool, type: string, expression: bool}> $indexes
     *
     * @return list<SchemaIndex>
     */
    private function indexes(array $indexes, bool &$withheld): array
    {
        $built = [];

        foreach ($indexes as $index) {
            $record = $this->index($index);

            if ($record === null) {
                $withheld = true;

                continue;
            }

            $built[] = $record;
        }

        usort($built, static fn (SchemaIndex $left, SchemaIndex $right): int => strcmp($left->name, $right->name));

        return $built;
    }

    /**
     * @param array{name: string, columns: list<string>, unique: bool, primary: bool, type: string, expression: bool} $index
     */
    private function index(array $index): ?SchemaIndex
    {
        if (! SafeName::isIdentifier($index['name'])) {
            return null;
        }

        $type = in_array($index['type'], ['btree', 'hash', 'fulltext', 'spatial'], true) ? $index['type'] : null;

        try {
            return new SchemaIndex(
                $index['name'],
                $index['columns'],
                $index['unique'] || $index['primary'],
                $index['primary'],
                $type,
                $index['expression'],
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param list<array{name: string, columns: list<string>, referenced_table: string, referenced_columns: list<string>, on_update: string, on_delete: string}> $foreignKeys
     *
     * @return list<SchemaForeignKey>
     */
    private function foreignKeys(array $foreignKeys, bool &$withheld): array
    {
        $built = [];

        foreach ($foreignKeys as $foreignKey) {
            $update = ReferentialAction::parse($foreignKey['on_update']);
            $delete = ReferentialAction::parse($foreignKey['on_delete']);

            if ($update === null || $delete === null) {
                $withheld = true;

                continue;
            }

            try {
                $built[] = new SchemaForeignKey(
                    $foreignKey['name'],
                    $foreignKey['columns'],
                    $foreignKey['referenced_table'],
                    $foreignKey['referenced_columns'],
                    $update,
                    $delete,
                );
            } catch (\InvalidArgumentException) {
                $withheld = true;
            }
        }

        usort($built, static fn (SchemaForeignKey $left, SchemaForeignKey $right): int => strcmp($left->name, $right->name));

        return $built;
    }

    /**
     * @param list<SchemaIndex> $indexes
     *
     * @return list<string>
     */
    private function primaryKey(array $indexes): array
    {
        foreach ($indexes as $index) {
            if ($index->primary) {
                return $index->columns;
            }
        }

        return [];
    }

    /**
     * @param list<SchemaColumn> $columns
     * @param list<string> $primary
     *
     * @return list<SchemaColumn>
     */
    private function markPrimary(array $columns, array $primary): array
    {
        $marked = [];

        foreach ($columns as $column) {
            if ($column->primary === in_array($column->name, $primary, true)) {
                $marked[] = $column;

                continue;
            }

            $marked[] = new SchemaColumn(
                $column->name,
                $column->typeName,
                $column->columnType,
                $column->nullable,
                $column->defaultPresent,
                $column->defaultSensitive,
                $column->defaultValue,
                in_array($column->name, $primary, true),
                $column->autoIncrement,
                $column->generated,
                $column->generation,
                $column->characterLength,
                $column->numericPrecision,
                $column->numericScale,
                $column->unsigned,
                $column->json,
                $column->booleanLike,
                $column->position,
            );
        }

        return $marked;
    }

    /**
     * @param list<SchemaIndex> $indexes
     *
     * @return list<SchemaUniqueConstraint>
     */
    private function uniqueConstraints(array $indexes): array
    {
        $constraints = [];

        foreach ($indexes as $index) {
            if (! $index->unique || $index->primary || $index->columns === []) {
                continue;
            }

            try {
                $constraints[] = new SchemaUniqueConstraint($index->name, $index->columns);
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return $constraints;
    }

    private function bounded(mixed $value, int $min, int $max): ?int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            return null;
        }

        return $value;
    }
}
