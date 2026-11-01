<?php

declare(strict_types=1);

namespace Beacon\Exceptions;

/**
 * A migration-risk query that Beacon will not run.
 *
 * Messages name the filter and the rule. They never repeat the rejected value.
 */
final class InvalidRiskQueryException extends \InvalidArgumentException implements BeaconException
{
    public static function unknownParameter(): self
    {
        return new self('Unknown migration risk filter. Allowed filters are connection, migration, path, category, severity, pending, include_executed, page and limit.');
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

    public static function path(): self
    {
        return new self('The path filter must be a registered migration path.');
    }

    public static function category(): self
    {
        return new self('The category filter must be data_loss, locking, compatibility, existing_data, performance, foreign_key, or transaction.');
    }

    public static function severity(): self
    {
        return new self('The severity filter must be critical, high, medium, low, or informational.');
    }

    public static function selection(): self
    {
        return new self('Select pending migrations, executed migrations, or one migration name.');
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
