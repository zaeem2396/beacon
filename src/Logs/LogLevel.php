<?php

declare(strict_types=1);

namespace Beacon\Logs;

enum LogLevel: string
{
    case Emergency = 'emergency';
    case Alert = 'alert';
    case Critical = 'critical';
    case Error = 'error';
    case Warning = 'warning';
    case Notice = 'notice';
    case Info = 'info';
    case Debug = 'debug';

    public function rank(): int
    {
        return match ($this) {
            self::Emergency => 7,
            self::Alert => 6,
            self::Critical => 5,
            self::Error => 4,
            self::Warning => 3,
            self::Notice => 2,
            self::Info => 1,
            self::Debug => 0,
        };
    }

    public static function tryFromLoose(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }
}
