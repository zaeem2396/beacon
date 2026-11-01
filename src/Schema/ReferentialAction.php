<?php

declare(strict_types=1);

namespace Beacon\Schema;

/**
 * A foreign-key action reported by the database.
 *
 * The value is the database rule, lowercased. It is not inferred from a
 * constraint name.
 */
enum ReferentialAction: string
{
    case Cascade = 'cascade';
    case Restrict = 'restrict';
    case SetNull = 'set null';
    case NoAction = 'no action';
    case SetDefault = 'set default';

    public static function parse(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }
}
