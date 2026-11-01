<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Data\MigrationInspection;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;

/**
 * @implements Inspector<MigrationInspection>
 */
final class FailingMigrationInspector implements Inspector
{
    public const DETAILS = [
        'mysql://beacon:migration-inspector-secret@db.internal/app',
        '/tmp/migration-inspector-path-not-in-output',
        'SQLSTATE[HY000] [1045] Access denied',
    ];

    public function capability(): Capability
    {
        return InspectionCapability::Migrations->capability();
    }

    public function inspect(array $parameters = []): Data
    {
        throw new \RuntimeException(implode(' ', self::DETAILS));
    }
}
