<?php

declare(strict_types=1);

namespace Beacon\Risk;

/**
 * How serious a finding is if the operation runs.
 *
 * Severity is not confidence. A critical finding can still be uncertain.
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

    public function meets(self $minimum): bool
    {
        return $this->rank() >= $minimum->rank();
    }
}
