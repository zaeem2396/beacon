<?php

declare(strict_types=1);

namespace Beacon\Migrations;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * A resolver that can only see the connection migration inspection opened.
 *
 * It does not change the application's default connection.
 */
final class FixedConnectionResolver implements ConnectionResolverInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function connection($name = null)
    {
        return $this->connection;
    }

    public function getDefaultConnection()
    {
        return 'beacon-migrations';
    }

    public function setDefaultConnection($name): void
    {
    }
}
