<?php

declare(strict_types=1);

namespace Beacon\Database;

use Beacon\Health\LaravelDatabaseProbe;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;

/**
 * Opens a short-lived connection, reads the server version, and disconnects.
 *
 * The statements are fixed. MySQL and MariaDB use version(). SQLite uses
 * sqlite_version(). The connection is not registered on the database manager.
 */
final class LaravelStatusProbe implements StatusProbe
{
    public function __construct(
        private readonly ConnectionFactory $factory,
    ) {
    }

    public function measure(array $config, int $timeoutSeconds): ProbeMeasurement
    {
        $started = hrtime(true);
        $prepared = LaravelDatabaseProbe::prepare($config, $timeoutSeconds);
        $connection = $this->factory->make($prepared, 'beacon-database-status');

        try {
            $ping = $connection->select('select 1 as beacon_status');

            if ($ping === []) {
                throw new \RuntimeException('The database status probe returned no row.');
            }

            $driver = $prepared['driver'] ?? null;
            $driver = is_string($driver) ? strtolower($driver) : '';
            $sql = match ($driver) {
                'mysql', 'mariadb' => 'select version() as beacon_version',
                'sqlite' => 'select sqlite_version() as beacon_version',
                default => null,
            };
            $version = $sql === null ? '' : $this->readVersion($connection, $sql);

            return new ProbeMeasurement($version, $this->milliseconds($started));
        } finally {
            $connection->disconnect();
        }
    }

    private function readVersion(Connection $connection, string $sql): string
    {
        try {
            $rows = $connection->select($sql);
        } catch (\Throwable) {
            return '';
        }

        $row = $rows[0] ?? null;
        $fields = is_object($row) ? get_object_vars($row) : [];
        $value = $fields['beacon_version'] ?? null;

        return is_string($value) || is_numeric($value) ? (string) $value : '';
    }

    private function milliseconds(int $started): int
    {
        $elapsed = (int) round((hrtime(true) - $started) / 1_000_000);

        return max(0, min(120000, $elapsed));
    }
}
