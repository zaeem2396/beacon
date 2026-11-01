<?php

declare(strict_types=1);

namespace Beacon\Exceptions;

/**
 * A schema query that Beacon will not run.
 *
 * Messages name the filter and the rule. They never repeat the rejected value.
 */
final class InvalidSchemaQueryException extends \InvalidArgumentException implements BeaconException
{
    public static function unknownParameter(): self
    {
        return new self('Unknown schema filter. Allowed filters are connection, table, include_views, include_columns, include_indexes, include_foreign_keys, page and limit.');
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

    public static function page(): self
    {
        return new self('The page filter must be an integer from 1 to 10000.');
    }

    public static function limit(): self
    {
        return new self('The limit filter must be an integer from 1 to 50.');
    }
}
