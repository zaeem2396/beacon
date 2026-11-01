<?php

declare(strict_types=1);

namespace Beacon\Exceptions;

/**
 * A database-status request that Beacon will not run.
 *
 * The message does not repeat the rejected value. The default connection and
 * timeout come from application configuration, not from the caller.
 */
final class InvalidDatabaseQueryException extends \InvalidArgumentException implements BeaconException
{
    public static function arguments(): self
    {
        return new self('Database status does not accept arguments.');
    }
}
