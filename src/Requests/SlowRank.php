<?php

declare(strict_types=1);

namespace Beacon\Requests;

/**
 * How far a slow request sits above its threshold. Not a defect diagnosis.
 */
enum SlowRank: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public static function fromExcess(int $durationMs, int $thresholdMs): self
    {
        if ($thresholdMs < 1 || $durationMs < $thresholdMs * 2) {
            return self::Low;
        }

        if ($durationMs < $thresholdMs * 5) {
            return self::Medium;
        }

        return self::High;
    }
}
