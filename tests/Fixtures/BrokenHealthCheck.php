<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Beacon\Contracts\HealthCheck;
use Beacon\Data\HealthCheckResult;

final class BrokenHealthCheck implements HealthCheck
{
    public function name(): string
    {
        throw new \RuntimeException('name-secret-not-in-output');
    }

    public function description(): string
    {
        throw new \RuntimeException('description-secret-not-in-output');
    }

    public function check(): HealthCheckResult
    {
        throw new \RuntimeException('check-secret-not-in-output');
    }
}
