<?php

declare(strict_types=1);

namespace Beacon\Exceptions;

/**
 * A schema snapshot Beacon will not load.
 *
 * Messages name the rule. They never repeat the rejected document.
 */
final class InvalidSnapshotException extends \InvalidArgumentException implements BeaconException
{
    public static function malformed(): self
    {
        return new self('The snapshot JSON is malformed.');
    }

    public static function tooLarge(): self
    {
        return new self('The snapshot is larger than the allowed size.');
    }

    public static function version(): self
    {
        return new self('The snapshot format version is not supported.');
    }

    public static function shape(): self
    {
        return new self('The snapshot shape is not valid.');
    }

    public static function driver(): self
    {
        return new self('The snapshot driver is not supported.');
    }

    public static function name(): self
    {
        return new self('The snapshot name must be a plain file name in the snapshot directory.');
    }

    public static function unavailable(): self
    {
        return new self('The snapshot directory is not available.');
    }

    public static function missing(): self
    {
        return new self('The snapshot file was not found.');
    }
}
