<?php

declare(strict_types=1);

namespace Beacon\Inspectors;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Data\HealthInspection;
use Beacon\Exceptions\InvalidHealthQueryException;
use Beacon\Health\Clock;
use Beacon\Health\HealthChecks;
use Beacon\Health\HealthRunner;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;

/**
 * Runs the configured health checks and returns one report.
 *
 * Callers cannot enable a probe or change its timeout. Those limits live in
 * beacon.health. The inspector does not run during a normal HTTP request.
 *
 * @implements Inspector<HealthInspection>
 */
final class HealthInspector implements Inspector
{
    public const NAME = 'health';

    public function __construct(
        private readonly HealthChecks $checks,
        private readonly Clock $clock,
    ) {
    }

    public function capability(): Capability
    {
        return InspectionCapability::Health->capability();
    }

    public function inspect(array $parameters = []): Data
    {
        if ($parameters !== []) {
            throw InvalidHealthQueryException::arguments();
        }

        return (new HealthRunner($this->checks->all(), $this->clock))->run();
    }
}
