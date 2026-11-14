<?php

declare(strict_types=1);

namespace Beacon\Requests;

/**
 * Validates or generates safe request correlation identifiers.
 */
final class CorrelationId
{
    public const MAX_LENGTH = 64;

    public const MIN_LENGTH = 8;

    /**
     * @var list<string>
     */
    public const HEADER_CANDIDATES = [
        'X-Request-Id',
        'X-Correlation-Id',
        'X-Beacon-Request-Id',
    ];

    public static function isValid(string $value): bool
    {
        $length = strlen($value);

        return $length >= self::MIN_LENGTH
            && $length <= self::MAX_LENGTH
            && preg_match('/^[A-Za-z0-9_-]+$/', $value) === 1;
    }

    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @param array<string, array<int, string|null>|string|null> $headers
     */
    public static function fromHeaders(array $headers): string
    {
        foreach (self::HEADER_CANDIDATES as $name) {
            $value = $headers[$name] ?? $headers[strtolower($name)] ?? null;

            if (is_array($value)) {
                $value = $value[0] ?? null;
            }

            if (is_string($value) && self::isValid(trim($value))) {
                return trim($value);
            }
        }

        return self::generate();
    }
}
