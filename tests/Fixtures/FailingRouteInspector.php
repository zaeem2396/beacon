<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Data\RouteInspection;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;

/**
 * Test-only replacement for the route inspector that fails with internal
 * details in its exception message.
 *
 * @implements Inspector<RouteInspection>
 */
final class FailingRouteInspector implements Inspector
{
    public const DETAILS = [
        'mysql://beacon:route-inspector-secret@db.internal/app',
        '/tmp/route-inspector-path-not-in-output',
        'SQLSTATE[HY000] [1045] Access denied',
    ];

    public function capability(): Capability
    {
        return InspectionCapability::Routes->capability();
    }

    public function inspect(array $parameters = []): Data
    {
        throw new \RuntimeException(implode(' ', self::DETAILS));
    }
}
