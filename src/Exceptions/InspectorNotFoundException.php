<?php

declare(strict_types=1);

namespace Beacon\Exceptions;

final class InspectorNotFoundException extends \RuntimeException implements BeaconException
{
    public static function named(string $name): self
    {
        return new self("Inspector [{$name}] is not registered.");
    }

    public static function disabled(string $name): self
    {
        return new self("Inspector [{$name}] is disabled.");
    }
}
