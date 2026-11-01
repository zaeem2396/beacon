<?php

declare(strict_types=1);

namespace Beacon\Schema;

/**
 * A column default that is safe to disclose.
 *
 * Sensitive column names and expressions are withheld. The raw SQL default
 * is not returned.
 */
final readonly class ColumnDefault
{
    public function __construct(
        public bool $present,
        public bool $sensitive,
        public ?string $value,
    ) {
    }

    public static function from(string $column, mixed $raw): self
    {
        if ($raw === null) {
            return new self(false, false, null);
        }

        if (self::sensitiveColumn($column)) {
            return new self(true, true, null);
        }

        if (! is_string($raw) && ! is_int($raw) && ! is_float($raw)) {
            return new self(true, true, null);
        }

        $text = trim((string) $raw);

        if ($text === '' || strlen($text) > 64 || self::sensitiveText($text)) {
            return new self(true, true, null);
        }

        $literal = self::literal($text);

        if ($literal === null) {
            return new self(true, true, null);
        }

        return new self(true, false, $literal);
    }

    private static function sensitiveColumn(string $column): bool
    {
        $name = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $column));

        foreach (['password', 'passwd', 'passphrase', 'secret', 'token', 'credential', 'apikey'] as $fragment) {
            if (str_contains($name, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private static function sensitiveText(string $text): bool
    {
        return preg_match('/(?:^|[^a-z])(password|passwd|secret|token|api[_-]?key)[^a-z]/i', ' ' . $text . ' ') === 1
            || str_contains($text, '://')
            || str_contains($text, '@');
    }

    private static function literal(string $text): ?string
    {
        if (preg_match('/^-?\d+(?:\.\d+)?$/', $text) === 1) {
            return $text;
        }

        if (preg_match('/^current_timestamp(?:\(\d*\))?$/i', $text) === 1) {
            return 'CURRENT_TIMESTAMP';
        }

        if (preg_match("/^'(?:[^'\\\\]|''){0,40}'$/", $text) !== 1) {
            return null;
        }

        $inner = str_replace("''", "'", substr($text, 1, -1));

        if (preg_match('/^[A-Za-z0-9_ .+-]{0,40}$/', $inner) !== 1 || self::sensitiveText($inner)) {
            return null;
        }

        return $inner;
    }
}
