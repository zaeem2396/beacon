<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Data\SchemaInspection;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;

/**
 * @implements Inspector<SchemaInspection>
 */
final class FailingSchemaInspector implements Inspector
{
    public const DETAILS = [
        'mysql://beacon:schema-inspector-secret@db.internal/app',
        '/tmp/schema-inspector-path-not-in-output',
        'SQLSTATE[HY000] [1045] Access denied',
    ];

    public function capability(): Capability
    {
        return InspectionCapability::Schema->capability();
    }

    public function inspect(array $parameters = []): Data
    {
        throw new \RuntimeException(implode(' ', self::DETAILS));
    }
}
