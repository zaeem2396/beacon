<?php

declare(strict_types=1);

namespace Beacon\Health;

enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Warning = 'warning';
    case Critical = 'critical';
    case Unknown = 'unknown';
}
