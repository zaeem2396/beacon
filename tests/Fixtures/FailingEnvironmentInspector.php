<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Data\EnvironmentInspection;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;

/**
 * Test-only replacement for the environment inspector that fails with
 * internal details in its exception message.
 *
 * @implements Inspector<EnvironmentInspection>
 */
final class FailingEnvironmentInspector implements Inspector
{
    public const DETAILS = [
        'mysql://beacon:inspector-secret@db.internal/app',
        '/var/www/beacon/vendor/laravel/framework/src/Illuminate/Foundation/Application.php',
        'SQLSTATE[HY000] [1045] Access denied',
    ];

    public function capability(): Capability
    {
        return InspectionCapability::Environment->capability();
    }

    public function inspect(array $parameters = []): Data
    {
        throw new \RuntimeException(implode(' ', self::DETAILS));
    }
}
