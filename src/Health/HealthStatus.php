<?php

declare(strict_types=1);

namespace Beacon\Health;

/**
 * The status of one health check or of the whole report.
 *
 * healthy: the check completed and passed.
 * warning: the check completed and found a degraded condition.
 * critical: the check completed and found a confirmed failure.
 * unknown: the check could not determine health.
 * skipped: the check was intentionally not run.
 *
 * Unknown and skipped are never counted as healthy.
 */
enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Warning = 'warning';
    case Critical = 'critical';
    case Unknown = 'unknown';
    case Skipped = 'skipped';
}
