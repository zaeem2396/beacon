<?php

declare(strict_types=1);

namespace Beacon\Schema;

/**
 * Short prose that cannot carry a URL, path, host, or driver error.
 */
final class Prose
{
    public static function isSafe(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9 .,_-]{0,239}$/', $value) === 1;
    }
}
