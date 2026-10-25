<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Data\ConfigurationInspection;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;

/**
 * Test-only replacement for the configuration inspector that fails with
 * internal details in its exception message.
 *
 * @implements Inspector<ConfigurationInspection>
 */
final class FailingConfigurationInspector implements Inspector
{
    public const DETAILS = [
        'mysql://beacon:configuration-inspector-secret@db.internal/app',
        '/tmp/configuration-inspector-path-not-in-output',
        'SQLSTATE[HY000] [1045] Access denied',
    ];

    public function capability(): Capability
    {
        return InspectionCapability::Config->capability();
    }

    public function inspect(array $parameters = []): Data
    {
        throw new \RuntimeException(implode(' ', self::DETAILS));
    }
}
