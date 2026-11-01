<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Database\ConnectionStatus;
use Beacon\Health\SafeName;

/**
 * Read-only status of the default database connection.
 *
 * The connection field is the Laravel connection name, not a host, database
 * name, or username. Version is a short server version. A failed probe does
 * not carry the driver error.
 */
final readonly class DatabaseStatus extends DataObject
{
    public const SCHEMA_VERSION = 1;

    /**
     * @var list<string>
     */
    public const DRIVERS = ['mariadb', 'mysql', 'pgsql', 'sqlite', 'sqlsrv'];

    /**
     * @var list<string>
     */
    public const PROBED_DRIVERS = ['mariadb', 'mysql', 'sqlite'];

    public int $schemaVersion;

    public function __construct(
        public ?string $connection,
        public ?string $driver,
        public ConnectionStatus $status,
        public ?string $version,
        public ?int $latencyMs,
        public string $summary,
    ) {
        if ($connection !== null && ! SafeName::isIdentifier($connection)) {
            throw new \InvalidArgumentException('Database connection name must be a short identifier.');
        }

        if ($driver !== null && ! in_array($driver, self::DRIVERS, true)) {
            throw new \InvalidArgumentException('Database driver must be a recognised value.');
        }

        if ($version !== null && preg_match('/^\d+\.\d+\.\d+(?:-MariaDB)?$/', $version) !== 1) {
            throw new \InvalidArgumentException('Database version must be a short server version.');
        }

        if ($latencyMs !== null && ($latencyMs < 0 || $latencyMs > 120000)) {
            throw new \InvalidArgumentException('Database latency is outside the allowed range.');
        }

        if ($status === ConnectionStatus::Connected && $latencyMs === null) {
            throw new \InvalidArgumentException('A connected database status includes latency.');
        }

        if ($status !== ConnectionStatus::Connected && ($latencyMs !== null || $version !== null)) {
            throw new \InvalidArgumentException('Only a connected database status includes version or latency.');
        }

        if ($status === ConnectionStatus::Unsupported && ! in_array($driver, ['pgsql', 'sqlsrv'], true)) {
            throw new \InvalidArgumentException('An unsupported database status names PostgreSQL or SQL Server.');
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 .,_-]{0,239}$/', $summary) !== 1) {
            throw new \InvalidArgumentException('Database status summary must be short prose.');
        }

        $this->schemaVersion = self::SCHEMA_VERSION;
    }
}
