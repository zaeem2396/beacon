<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Data\HealthInspection;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;

/**
 * Test-only replacement for the health inspector that fails with internal
 * details in its exception message.
 *
 * @implements Inspector<HealthInspection>
 */
final class FailingHealthInspector implements Inspector
{
    public const DETAILS = [
        'mysql://beacon:health-inspector-secret@db.internal/app',
        '/tmp/health-inspector-path-not-in-output',
        'SQLSTATE[HY000] [1045] Access denied',
    ];

    public function capability(): Capability
    {
        return InspectionCapability::Health->capability();
    }

    public function inspect(array $parameters = []): Data
    {
        throw new \RuntimeException(implode(' ', self::DETAILS));
    }
}
