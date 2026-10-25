<?php

declare(strict_types=1);

namespace Beacon\Contracts;

use Beacon\Data\HealthCheckResult;

/**
 * One read-only health check.
 *
 * Implementations return a normalized result. They do not talk to MCP, and a
 * failure in one check must not be allowed to escape to the caller: the
 * runner converts an unexpected throw into an unknown result.
 */
interface HealthCheck
{
    public function name(): string;

    public function description(): string;

    public function check(): HealthCheckResult;
}
