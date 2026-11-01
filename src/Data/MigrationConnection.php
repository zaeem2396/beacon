<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Database\ConnectionStatus;
use Beacon\Health\SafeName;

/**
 * The Laravel connection migration inspection used.
 *
 * The name is the connection name, not a host, database, or username.
 */
final readonly class MigrationConnection extends DataObject
{
    /**
     * @var list<string>
     */
    public const DRIVERS = ['mariadb', 'mysql', 'pgsql', 'sqlite', 'sqlsrv'];

    /**
     * @var list<string>
     */
    public const OPENED_DRIVERS = ['mariadb', 'mysql', 'sqlite'];

    public function __construct(
        public ?string $name,
        public ?string $driver,
        public ConnectionStatus $status,
    ) {
        if ($name !== null && ! SafeName::isIdentifier($name)) {
            throw new \InvalidArgumentException('Migration connection name must be a plain identifier.');
        }

        if ($driver !== null && ! in_array($driver, self::DRIVERS, true)) {
            throw new \InvalidArgumentException('Migration connection driver must be a recognised value.');
        }

        if ($status === ConnectionStatus::Unsupported && ! in_array($driver, ['pgsql', 'sqlsrv'], true)) {
            throw new \InvalidArgumentException('An unsupported migration connection names a driver this release does not open.');
        }
    }
}
