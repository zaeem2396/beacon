<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Database\ConnectionStatus;
use Beacon\Database\DatabaseVersion;
use Beacon\Health\SafeName;

/**
 * The connection schema inspection used.
 *
 * database is the selected schema name when it is a plain identifier.
 * Hosts, users, and passwords are not represented.
 */
final readonly class SchemaConnection extends DataObject
{
    public function __construct(
        public ?string $name,
        public ?string $driver,
        public ?string $database,
        public ?string $version,
        public ConnectionStatus $status,
    ) {
        if ($name !== null && ! SafeName::isIdentifier($name)) {
            throw new \InvalidArgumentException('Schema connection name must be a plain identifier.');
        }

        if ($driver !== null && ! in_array($driver, DatabaseStatus::DRIVERS, true)) {
            throw new \InvalidArgumentException('Schema connection driver must be a recognised value.');
        }

        if ($database !== null && ! SafeName::isIdentifier($database)) {
            throw new \InvalidArgumentException('Schema database name must be a plain identifier.');
        }

        if ($version !== null && DatabaseVersion::sanitize($version) !== $version) {
            throw new \InvalidArgumentException('Schema database version must be a short server version.');
        }

        if ($status === ConnectionStatus::Unsupported && ! in_array($driver, ['pgsql', 'sqlite', 'sqlsrv'], true)) {
            throw new \InvalidArgumentException('An unsupported schema connection names a driver this release does not open.');
        }
    }
}
