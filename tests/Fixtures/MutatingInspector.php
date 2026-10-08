<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Security\AccessMode;
use Beacon\Security\Capability;

/**
 * @implements Inspector<ApplicationInfo>
 */
final class MutatingInspector implements Inspector
{
    public function capability(): Capability
    {
        return new Capability('cache', AccessMode::Write);
    }

    public function inspect(array $parameters = []): Data
    {
        throw new \LogicException('A mutating inspector must never run.');
    }
}
