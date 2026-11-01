<?php

declare(strict_types=1);

namespace Beacon\Migrations;

/**
 * A migration name that is safe to return and compare.
 *
 * Names may start with a digit because Laravel prefixes them with a date.
 * Dots, slashes, and spaces are rejected so a name cannot be a path.
 */
final class MigrationName
{
    public static function isSafe(string $name): bool
    {
        $length = strlen($name);

        return $length >= 1
            && $length <= 200
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9_]*$/', $name) === 1
            && str_contains($name, '_');
    }

    public static function filenameAt(string $name): ?string
    {
        if (preg_match('/^(\d{4})_(\d{2})_(\d{2})_(\d{6})_/', $name, $match) !== 1) {
            return null;
        }

        $clock = $match[4];
        $stamp = $match[1] . '-' . $match[2] . '-' . $match[3] . ' '
            . substr($clock, 0, 2) . ':' . substr($clock, 2, 2) . ':' . substr($clock, 4, 2);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $stamp, new \DateTimeZone('UTC'));

        if (! $parsed instanceof \DateTimeImmutable || $parsed->format('Y-m-d H:i:s') !== $stamp) {
            return null;
        }

        return $parsed->format('Y-m-d\TH:i:s\+00:00');
    }
}
