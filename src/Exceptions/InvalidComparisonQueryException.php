<?php

declare(strict_types=1);

namespace Beacon\Exceptions;

/**
 * A schema comparison request Beacon will not run.
 *
 * Messages name the filter and the rule. They never repeat the rejected value.
 */
final class InvalidComparisonQueryException extends \InvalidArgumentException implements BeaconException
{
    public static function unknownParameter(): self
    {
        return new self('Unknown comparison filter. Allowed filters are baseline, candidate, baseline_live, candidate_live, connection, category, severity, object, detect_renames, page and limit.');
    }

    public static function type(string $parameter): self
    {
        return new self('The ' . $parameter . ' filter has an unsupported type.');
    }

    public static function selection(): self
    {
        return new self('Provide two snapshots, or one snapshot and the live schema. Both sides cannot be live.');
    }

    public static function category(): self
    {
        return new self('The category filter must be table, column, primary_key, index, foreign_key, view, or metadata.');
    }

    public static function severity(): self
    {
        return new self('The severity filter must be critical, high, medium, low, or informational.');
    }

    public static function object(): self
    {
        return new self('The object filter must be a plain identifier.');
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
