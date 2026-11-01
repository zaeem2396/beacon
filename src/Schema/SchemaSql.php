<?php

declare(strict_types=1);

namespace Beacon\Schema;

/**
 * Rejects every statement schema inspection is not allowed to run.
 *
 * The only permitted statements are metadata selects and the session
 * read-only guard. Callers cannot supply the SQL.
 */
final class SchemaSql
{
    public static function isReadOnly(string $sql): bool
    {
        return preg_match('/^(select|set session transaction read only)\b/i', ltrim($sql)) === 1;
    }
}
