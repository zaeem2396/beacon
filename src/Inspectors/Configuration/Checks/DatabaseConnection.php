<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Configuration\Checks;

/**
 * The default database connection must name a registered connection and a
 * recognised driver. This check does not open the connection.
 */
final class DatabaseConnection extends ReferenceCheck
{
    public const ID = 'database_connection';

    protected function checkId(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return 'Default database connection';
    }

    protected function nameId(): string
    {
        return 'database.default';
    }

    protected function definitionId(): string
    {
        return 'database.connection';
    }

    protected function driverId(): string
    {
        return 'database.driver';
    }

    protected function subject(): string
    {
        return 'default database connection';
    }
}
