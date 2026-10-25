<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Beacon\Contracts\HealthCheck;
use Beacon\Data\HealthCheckResult;

final class StaticHealthCheck implements HealthCheck
{
    public function __construct(
        private readonly string $checkName,
        private readonly string $checkDescription,
        private readonly HealthCheckResult|\Throwable $outcome,
    ) {
    }

    public function name(): string
    {
        return $this->checkName;
    }

    public function description(): string
    {
        return $this->checkDescription;
    }

    public function check(): HealthCheckResult
    {
        if ($this->outcome instanceof \Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}
