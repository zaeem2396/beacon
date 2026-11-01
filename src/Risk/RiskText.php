<?php

declare(strict_types=1);

namespace Beacon\Risk;

/**
 * Prose and snippets that are safe to return from risk analysis.
 *
 * Prose cannot carry a path, URL, or quote. Snippets are reconstructed
 * operation text with a short allowlist, not arbitrary file contents.
 */
final class RiskText
{
    public static function prose(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 .,_-]{0,319}$/', $value) !== 1) {
            throw new \InvalidArgumentException('Risk text must be plain prose.');
        }

        return $value;
    }

    public static function snippet(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        if (strlen($value) > 160) {
            $value = substr($value, 0, 160);
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 .,_()\-><=:\'\"]{0,159}$/', $value) !== 1) {
            return 'Recognized migration operation';
        }

        return $value;
    }
}
