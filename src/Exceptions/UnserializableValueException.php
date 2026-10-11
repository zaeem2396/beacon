<?php

declare(strict_types=1);

namespace Beacon\Exceptions;

final class UnserializableValueException extends \InvalidArgumentException implements BeaconException
{
    public static function forValue(mixed $value): self
    {
        return new self(sprintf(
            'Values of type [%s] cannot cross the Beacon data boundary. Map them to a Beacon data object first.',
            get_debug_type($value),
        ));
    }

    public static function nonFiniteFloat(): self
    {
        return new self('Non-finite floats (INF, NAN) cannot cross the Beacon data boundary.');
    }
}
