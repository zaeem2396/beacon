<?php

declare(strict_types=1);

namespace Beacon\Comparison;

/**
 * How serious a structural difference is if it reaches a deployment.
 *
 * Severity is not proof that a deployment will fail or that rows were lost.
 */
enum Severity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Informational = 'informational';

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 4,
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
            self::Informational => 0,
        };
    }
}
