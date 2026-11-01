<?php

declare(strict_types=1);

namespace Beacon\Risk;

/**
 * Classifies a literal SQL string without executing it.
 *
 * Only a leading statement shape is recognized. Dynamic SQL never reaches
 * this classifier.
 */
final class SqlClassifier
{
    public static function effect(string $sql): string
    {
        $sql = self::normalize($sql);

        if ($sql === '') {
            return 'unrecognized';
        }

        return match (true) {
            preg_match('/^(DROP DATABASE|DROP SCHEMA)\b/', $sql) === 1 => 'drop_database',
            preg_match('/^DROP TABLE\b/', $sql) === 1 => 'drop_table',
            preg_match('/^TRUNCATE\b/', $sql) === 1 => 'truncate',
            preg_match('/^DROP INDEX\b/', $sql) === 1 => 'drop_index',
            preg_match('/^CREATE UNIQUE INDEX\b/', $sql) === 1 => 'add_unique',
            preg_match('/^CREATE INDEX\b/', $sql) === 1 => 'create_index',
            preg_match('/^DELETE\b/', $sql) === 1 => 'delete_rows',
            preg_match('/^UPDATE\b/', $sql) === 1 => 'update_rows',
            preg_match('/^ALTER TABLE\b/', $sql) === 1 && preg_match('/\bDROP COLUMN\b/', $sql) === 1 => 'drop_column',
            preg_match('/^ALTER TABLE\b/', $sql) === 1 && preg_match('/\bADD UNIQUE\b/', $sql) === 1 => 'add_unique',
            preg_match('/^ALTER TABLE\b/', $sql) === 1 && preg_match('/\bADD (INDEX|KEY|FULLTEXT)\b/', $sql) === 1 => 'create_index',
            preg_match('/^ALTER TABLE\b/', $sql) === 1 && preg_match('/\bDROP (INDEX|KEY)\b/', $sql) === 1 => 'drop_index',
            preg_match('/^ALTER TABLE\b/', $sql) === 1 && preg_match('/\bFOREIGN KEY\b/', $sql) === 1 && preg_match('/\bDROP\b/', $sql) === 1 => 'drop_foreign',
            preg_match('/^ALTER TABLE\b/', $sql) === 1 && preg_match('/\bFOREIGN KEY\b/', $sql) === 1 => 'add_foreign',
            preg_match('/^ALTER TABLE\b/', $sql) === 1 => 'alter_table',
            preg_match('/^(SELECT|WITH)\b/', $sql) === 1 => 'select_rows',
            default => 'unrecognized',
        };
    }

    public static function table(string $sql): ?string
    {
        $sql = self::normalize($sql);

        $patterns = [
            '/^(?:DROP TABLE(?: IF EXISTS)?|TRUNCATE(?: TABLE)?|ALTER TABLE|UPDATE|DELETE FROM|CREATE(?: UNIQUE)? INDEX \S+ ON) [`\'"]?([A-Za-z_][A-Za-z0-9_]{0,63})/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sql, $match) === 1) {
                return strtolower($match[1]);
            }
        }

        return null;
    }

    private static function normalize(string $sql): string
    {
        $withoutBlocks = preg_replace('#/\*.*?\*/#s', ' ', $sql);
        $withoutLines = preg_replace('/--[^\n]*/', ' ', is_string($withoutBlocks) ? $withoutBlocks : $sql);
        $collapsed = preg_replace('/\s+/', ' ', is_string($withoutLines) ? $withoutLines : $sql);

        return strtoupper(trim(is_string($collapsed) ? $collapsed : ''));
    }
}
