<?php

declare(strict_types=1);

namespace Beacon\Exceptions;

/**
 * A route query that Beacon will not run.
 *
 * Messages name the filter and the rule. They never repeat the rejected value.
 */
final class InvalidRouteQueryException extends \InvalidArgumentException implements BeaconException
{
    public static function unknownParameter(): self
    {
        return new self('Unknown route filter. Allowed filters are method, uri, uri_match, name, name_match, middleware, page and limit.');
    }

    public static function type(string $parameter): self
    {
        return new self('The ' . $parameter . ' filter has an unsupported type.');
    }

    public static function empty(string $parameter): self
    {
        return new self('The ' . $parameter . ' filter must not be empty.');
    }

    public static function method(): self
    {
        return new self('The method filter must be one HTTP verb: GET, HEAD, POST, PUT, PATCH, DELETE or OPTIONS.');
    }

    public static function match(string $parameter): self
    {
        return new self('The ' . $parameter . ' filter must be contains or exact.');
    }

    public static function characters(string $parameter): self
    {
        return new self('The ' . $parameter . ' filter contains characters that are not allowed.');
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
        return new self('The limit filter must be an integer from 1 to 100.');
    }
}
