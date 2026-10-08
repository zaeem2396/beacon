<?php

declare(strict_types=1);

namespace Beacon\Contracts;

use Beacon\Data\HealthCheckResult;

interface HealthCheck
{
    public function check(): HealthCheckResult;
}
