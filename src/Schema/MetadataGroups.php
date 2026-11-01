<?php

declare(strict_types=1);

namespace Beacon\Schema;

use Beacon\Health\SafeName;

/**
 * Groups information_schema rows by table.
 *
 * Unique is taken from non_unique. An index is not unique because its name
 * says so. Column order follows the database sequence.
 */
final class MetadataGroups
{
    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function columnsByTable(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $table = self::text($row['table_name'] ?? null);
            $name = self::text($row['column_name'] ?? null);

            if ($table === null || $name === null) {
                continue;
            }

            $grouped[$table][] = [
                'name' => $name,
                'ordinal' => self::integer($row['ordinal_position'] ?? null),
                'type_name' => strtolower(self::text($row['data_type'] ?? null) ?? ''),
                'column_type' => strtolower(self::text($row['column_type'] ?? null) ?? ''),
                'nullable' => strtoupper(self::text($row['is_nullable'] ?? null) ?? '') === 'YES',
                'default' => $row['column_default'] ?? null,
                'character_length' => self::integer($row['character_maximum_length'] ?? null),
                'numeric_precision' => self::integer($row['numeric_precision'] ?? null),
                'numeric_scale' => self::integer($row['numeric_scale'] ?? null),
                'extra' => self::text($row['extra'] ?? null) ?? '',
                'generation_expression' => self::text($row['generation_expression'] ?? null),
            ];
        }

        return $grouped;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, list<array{name: string, columns: list<string>, unique: bool, primary: bool, type: string, expression: bool}>>
     */
    public static function indexesByTable(array $rows): array
    {
        /** @var array<string, array<string, array{seqs: array<int, string|null>, unique: bool, type: string}>> $bucket */
        $bucket = [];

        foreach ($rows as $row) {
            $table = self::text($row['table_name'] ?? null);
            $name = self::text($row['index_name'] ?? null);

            if ($table === null || $name === null || $name === '') {
                continue;
            }

            $sequence = self::integer($row['seq_in_index'] ?? null) ?? 0;
            $column = self::text($row['column_name'] ?? null);
            $bucket[$table][$name]['seqs'][$sequence] = $column !== null && SafeName::isIdentifier($column) ? $column : null;
            $nonUnique = $row['non_unique'] ?? 1;
            $bucket[$table][$name]['unique'] = $nonUnique === 0 || $nonUnique === '0';
            $bucket[$table][$name]['type'] = strtolower(self::text($row['index_type'] ?? null) ?? '');
        }

        $grouped = [];

        foreach ($bucket as $table => $indexes) {
            foreach ($indexes as $name => $index) {
                ksort($index['seqs']);
                $columns = [];
                $expression = false;

                foreach ($index['seqs'] as $column) {
                    if ($column === null) {
                        $expression = true;

                        continue;
                    }

                    $columns[] = $column;
                }

                $lower = strtolower($name);
                $grouped[$table][] = [
                    'name' => $lower,
                    'columns' => $columns,
                    'unique' => $index['unique'],
                    'primary' => $lower === 'primary',
                    'type' => $index['type'],
                    'expression' => $expression,
                ];
            }
        }

        return $grouped;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, list<array{name: string, columns: list<string>, referenced_table: string, referenced_columns: list<string>, on_update: string, on_delete: string}>>
     */
    public static function foreignKeysByTable(array $rows): array
    {
        /** @var array<string, array<string, array{seqs: array<int, array{column: string, referenced: string}>, referenced_table: string, on_update: string, on_delete: string}>> $bucket */
        $bucket = [];

        foreach ($rows as $row) {
            $table = self::text($row['table_name'] ?? null);
            $name = self::text($row['constraint_name'] ?? null);
            $column = self::text($row['column_name'] ?? null);
            $referencedTable = self::text($row['referenced_table_name'] ?? null);
            $referencedColumn = self::text($row['referenced_column_name'] ?? null);

            if ($table === null || $name === null || $column === null || $referencedTable === null || $referencedColumn === null) {
                continue;
            }

            if (! SafeName::isIdentifier($column) || ! SafeName::isIdentifier($referencedColumn) || ! SafeName::isIdentifier($referencedTable)) {
                continue;
            }

            $sequence = self::integer($row['ordinal_position'] ?? null) ?? 0;
            $bucket[$table][$name]['seqs'][$sequence] = ['column' => $column, 'referenced' => $referencedColumn];
            $bucket[$table][$name]['referenced_table'] = $referencedTable;
            $bucket[$table][$name]['on_update'] = strtolower(self::text($row['update_rule'] ?? null) ?? '');
            $bucket[$table][$name]['on_delete'] = strtolower(self::text($row['delete_rule'] ?? null) ?? '');
        }

        $grouped = [];

        foreach ($bucket as $table => $constraints) {
            foreach ($constraints as $name => $constraint) {
                ksort($constraint['seqs']);
                $columns = [];
                $referenced = [];

                foreach ($constraint['seqs'] as $pair) {
                    $columns[] = $pair['column'];
                    $referenced[] = $pair['referenced'];
                }

                $grouped[$table][] = [
                    'name' => $name,
                    'columns' => $columns,
                    'referenced_table' => $constraint['referenced_table'],
                    'referenced_columns' => $referenced,
                    'on_update' => $constraint['on_update'],
                    'on_delete' => $constraint['on_delete'],
                ];
            }
        }

        return $grouped;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d{1,15}$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
