<?php

declare(strict_types=1);

namespace Beacon\Health;

use Illuminate\Database\Connectors\ConnectionFactory;

/**
 * Opens a short-lived connection, runs `select 1`, and disconnects.
 *
 * The connection is not registered on the database manager. The query does
 * not write, and the configuration copy is not stored on the result.
 */
final class LaravelDatabaseProbe implements DatabaseProbe
{
    public function __construct(
        private readonly ConnectionFactory $factory,
    ) {
    }

    /**
     * Copy a connection configuration and apply driver limits.
     *
     * Network drivers get a numeric PDO timeout. array_merge would renumber
     * that key, so the option is assigned directly. An sqlite file is opened
     * read-only when PHP provides that flag, so the probe does not create the
     * file. The input array is left unchanged.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function prepare(array $config, int $timeoutSeconds): array
    {
        $probe = $config;
        $driver = $probe['driver'] ?? null;
        $driver = is_string($driver) ? strtolower($driver) : '';

        if ($driver === 'sqlite') {
            return self::readOnlySqlite($probe);
        }

        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlsrv'], true)) {
            return $probe;
        }

        $options = $probe['options'] ?? [];
        $options = is_array($options) ? $options : [];
        $options[\PDO::ATTR_TIMEOUT] = $timeoutSeconds;
        $probe['options'] = $options;

        return $probe;
    }

    public function selectOne(array $config, int $timeoutSeconds): void
    {
        $connection = $this->factory->make(self::prepare($config, $timeoutSeconds), 'beacon-health');

        try {
            $rows = $connection->select('select 1 as beacon_health');

            if ($rows === []) {
                throw new \RuntimeException('The database probe returned no row.');
            }
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * @param array<string, mixed> $probe
     *
     * @return array<string, mixed>
     */
    private static function readOnlySqlite(array $probe): array
    {
        $database = $probe['database'] ?? null;

        if ($database === ':memory:' || ! defined('PDO::SQLITE_ATTR_OPEN_FLAGS') || ! defined('SQLITE_OPEN_READONLY')) {
            return $probe;
        }

        $options = $probe['options'] ?? [];
        $options = is_array($options) ? $options : [];
        $options[\PDO::SQLITE_ATTR_OPEN_FLAGS] = \SQLITE_OPEN_READONLY;
        $probe['options'] = $options;

        return $probe;
    }
}
