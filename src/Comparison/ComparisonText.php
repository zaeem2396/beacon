<?php

declare(strict_types=1);

namespace Beacon\Comparison;

use Beacon\Schema\Prose;

/**
 * Text that can be stored in a snapshot or a comparison result.
 */
final class ComparisonText
{
    public static function isVersion(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,80}$/', $value) === 1;
    }

    public static function isDefault(string $value): bool
    {
        return preg_match('/^(?:CURRENT_TIMESTAMP|-?\d+(?:\.\d+)?|[A-Za-z0-9_ .+-]{0,64})$/', $value) === 1;
    }

    public static function isValue(string $value): bool
    {
        return preg_match('/^(?:true|false|CURRENT_TIMESTAMP|-?\d+(?:\.\d+)?|[A-Za-z0-9][A-Za-z0-9_ .+-]{0,80}(?:, [A-Za-z0-9][A-Za-z0-9_ .+-]{0,80}){0,15})$/', $value) === 1;
    }

    public static function prose(string $value): string
    {
        if (! Prose::isSafe($value)) {
            throw new \InvalidArgumentException('Comparison text must be short prose.');
        }

        return $value;
    }

    public static function display(bool|int|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $text = is_int($value) ? (string) $value : $value;

        return self::isValue($text) ? $text : null;
    }

    /**
     * @param list<string> $names
     */
    public static function join(array $names): ?string
    {
        if ($names === []) {
            return null;
        }

        return self::display(implode(', ', $names));
    }
}
