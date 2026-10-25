<?php

declare(strict_types=1);

namespace Beacon\Exceptions;

/**
 * A health request that Beacon will not run.
 *
 * The message does not repeat the rejected value. Health probes are chosen
 * by application configuration, not by the caller.
 */
final class InvalidHealthQueryException extends \InvalidArgumentException implements BeaconException
{
    public static function arguments(): self
    {
        return new self('Health inspection does not accept arguments.');
    }
}
