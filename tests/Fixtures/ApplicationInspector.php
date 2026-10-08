<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Security\Capability;

/**
 * @implements Inspector<ApplicationInfo>
 */
final class ApplicationInspector implements Inspector
{
    /**
     * @var array<string, scalar|null>
     */
    public static array $lastParameters = [];

    public function capability(): Capability
    {
        return Capability::read('application');
    }

    public function inspect(array $parameters = []): Data
    {
        self::$lastParameters = $parameters;

        return new ApplicationInfo('13.0.0', '8.3.6', 'testing', 'Beacon Test');
    }
}
