<?php

declare(strict_types=1);

namespace Beacon\Exceptions;

/**
 * A migration query that Beacon will not run.
 *
 * Messages name the filter and the rule. They never repeat the rejected value.
 */
final class InvalidMigrationQueryException extends \InvalidArgumentException implements BeaconException
{
    public static function unknownParameter(): self
    {
        return new self('Unknown migration filter. Allowed filters are connection, name, status, batch, path, page and limit.');
    }

    public static function type(string $parameter): self
    {
        return new self('The ' . $parameter . ' filter has an unsupported type.');
    }

    public static function empty(string $parameter): self
    {
        return new self('The ' . $parameter . ' filter must not be empty.');
    }

    public static function characters(string $parameter): self
    {
        return new self('The ' . $parameter . ' filter must be a plain identifier.');
    }

    public static function length(string $parameter): self
    {
        return new self('The ' . $parameter . ' filter is too long.');
    }

    public static function status(): self
    {
        return new self('The status filter must be executed, pending, missing_file, duplicate, or unknown.');
    }

    public static function batch(): self
    {
        return new self('The batch filter must be an integer from 1 to 1000000.');
    }

    public static function path(): self
    {
        return new self('The path filter must be a registered migration path.');
    }

    public static function page(): self
    {
        return new self('The page filter must be an integer from 1 to 10000.');
    }

    public static function limit(): self
    {
        return new self('The limit filter must be an integer from 1 to 100.');
    }
}
