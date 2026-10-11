<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Contracts\Data;
use Beacon\Exceptions\UnserializableValueException;
use Illuminate\Support\Str;

/**
 * Converts Beacon data into plain, deterministic, JSON-compatible arrays.
 *
 * Only scalars, arrays, enums, dates and other Data objects are accepted.
 * Any other object is rejected so arbitrary Laravel objects can never leak
 * through the data boundary.
 */
final class Normalizer
{
    /**
     * @var array<class-string, array<string, string>> property name => output key
     */
    private static array $keys = [];

    /**
     * Normalize the public properties of a data object, keyed in snake_case
     * and in declaration order.
     *
     * @return array<string, mixed>
     */
    public static function properties(object $object): array
    {
        $result = [];

        foreach (self::keysFor($object) as $property => $key) {
            $result[$key] = self::normalize($object->{$property});
        }

        return $result;
    }

    /**
     *
     * @throws UnserializableValueException
     *
     * @return array<array-key, mixed>|scalar|null
     */
    public static function normalize(mixed $value): array|bool|float|int|string|null
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_string($value) => $value,
            is_float($value) => is_finite($value) ? $value : throw UnserializableValueException::nonFiniteFloat(),
            is_array($value) => array_map(self::normalize(...), $value),
            $value instanceof Data => self::normalize($value->toArray()),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \UnitEnum => $value->name,
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            default => throw UnserializableValueException::forValue($value),
        };
    }

    /**
     * @return array<string, string>
     */
    private static function keysFor(object $object): array
    {
        return self::$keys[$object::class] ??= self::resolveKeys($object);
    }

    /**
     * @return array<string, string>
     */
    private static function resolveKeys(object $object): array
    {
        $keys = [];

        foreach ((new \ReflectionClass($object))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if (! $property->isStatic()) {
                $keys[$property->getName()] = Str::snake($property->getName());
            }
        }

        return $keys;
    }
}
