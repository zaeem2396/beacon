<?php

declare(strict_types=1);

namespace Beacon\Exceptions;

/**
 * A configuration query that Beacon will not run.
 *
 * Messages name the filter and the rule. They never repeat the rejected value.
 */
final class InvalidConfigurationQueryException extends \InvalidArgumentException implements BeaconException
{
    public static function unknownParameter(): self
    {
        return new self('Unknown configuration filter. Allowed filters are category, id, page and limit.');
    }

    public static function type(string $parameter): self
    {
        return new self('The ' . $parameter . ' filter has an unsupported type.');
    }

    public static function empty(string $parameter): self
    {
        return new self('The ' . $parameter . ' filter must not be empty.');
    }

    public static function length(string $parameter): self
    {
        return new self('The ' . $parameter . ' filter is too long.');
    }

    public static function category(): self
    {
        return new self('The category filter must be one approved category: application, database, cache, queue, session, mail, filesystem or logging.');
    }

    public static function id(): self
    {
        return new self('The id filter must be an approved configuration setting.');
    }

    public static function mismatch(): self
    {
        return new self('The id filter is not in the selected category.');
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
