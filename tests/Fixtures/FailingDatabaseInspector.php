<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Data\DatabaseStatus;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;

/**
 * Test-only replacement for the database inspector that fails with internal
 * details in its exception message.
 *
 * @implements Inspector<DatabaseStatus>
 */
final class FailingDatabaseInspector implements Inspector
{
    public const DETAILS = [
        'mysql://beacon:database-inspector-secret@db.internal/app',
        '/tmp/database-inspector-path-not-in-output',
        'SQLSTATE[HY000] [1045] Access denied',
    ];

    public function capability(): Capability
    {
        return InspectionCapability::Database->capability();
    }

    public function inspect(array $parameters = []): Data
    {
        throw new \RuntimeException(implode(' ', self::DETAILS));
    }
}
