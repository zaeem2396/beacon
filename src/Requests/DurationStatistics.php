<?php

declare(strict_types=1);

namespace Beacon\Requests;

/**
 * Nearest-rank duration statistics. Missing values stay null.
 *
 * p95 requires at least 5 samples. p99 requires at least 20 samples.
 */
final class DurationStatistics
{
    public const P95_MINIMUM = 5;

    public const P99_MINIMUM = 20;

    /**
     * @param list<int> $values
     */
    public static function average(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 2);
    }

    /**
     * @param list<int> $values
     */
    public static function percentile(array $values, int $percentile): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $index = (int) ceil(($percentile / 100) * count($values)) - 1;
        $index = max(0, min(count($values) - 1, $index));

        return (float) $values[$index];
    }

    /**
     * @param list<int> $values
     */
    public static function median(array $values): ?float
    {
        return self::percentile($values, 50);
    }

    /**
     * @param list<int> $values
     */
    public static function p95(array $values): ?float
    {
        return count($values) >= self::P95_MINIMUM ? self::percentile($values, 95) : null;
    }

    /**
     * @param list<int> $values
     */
    public static function p99(array $values): ?float
    {
        return count($values) >= self::P99_MINIMUM ? self::percentile($values, 99) : null;
    }

    /**
     * @param list<int> $values
     */
    public static function maximum(array $values): ?int
    {
        return $values === [] ? null : max($values);
    }
}
