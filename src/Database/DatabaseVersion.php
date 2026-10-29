<?php

declare(strict_types=1);

namespace Beacon\Database;

/**
 * Keeps a short server version and drops packaging text, hosts, and paths.
 */
final class DatabaseVersion
{
    public static function sanitize(string $raw): ?string
    {
        if (preg_match('/^(\d+\.\d+\.\d+)/', $raw, $matches) !== 1) {
            return null;
        }

        $version = $matches[1];

        if (stripos($raw, 'mariadb') !== false) {
            $version .= '-MariaDB';
        }

        return preg_match('/^\d+\.\d+\.\d+(?:-MariaDB)?$/', $version) === 1 ? $version : null;
    }
}
