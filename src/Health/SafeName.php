<?php

declare(strict_types=1);

namespace Beacon\Health;

/**
 * A connection, store, or disk name that is safe to use as a single path
 * segment. Dotted names are rejected so a probe cannot walk nested keys.
 */
final class SafeName
{
    public static function isIdentifier(string $value): bool
    {
        $length = strlen($value);

        if ($length < 1 || $length > 64) {
            return false;
        }

        if (! str_contains('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', $value[0])) {
            return false;
        }

        for ($index = 1; $index < $length; $index++) {
            if (! str_contains('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-', $value[$index])) {
                return false;
            }
        }

        return true;
    }
}
