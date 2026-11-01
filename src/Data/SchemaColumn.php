<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Health\SafeName;

/**
 * Metadata for one column. The column's values are not included.
 */
final readonly class SchemaColumn extends DataObject
{
    public function __construct(
        public string $name,
        public string $typeName,
        public ?string $columnType,
        public bool $nullable,
        public bool $defaultPresent,
        public bool $defaultSensitive,
        public ?string $defaultValue,
        public bool $primary,
        public bool $autoIncrement,
        public bool $generated,
        public ?string $generation,
        public ?int $characterLength,
        public ?int $numericPrecision,
        public ?int $numericScale,
        public bool $unsigned,
        public bool $json,
        public bool $booleanLike,
        public int $position,
    ) {
        if (! SafeName::isIdentifier($name)) {
            throw new \InvalidArgumentException('Schema column name must be a plain identifier.');
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $typeName) !== 1) {
            throw new \InvalidArgumentException('Schema column type must be a plain type name.');
        }

        if ($columnType !== null && preg_match('/^[a-z0-9(), ]{1,64}$/', $columnType) !== 1) {
            throw new \InvalidArgumentException('Schema column type details must be plain type text.');
        }

        if (! $defaultPresent && ($defaultSensitive || $defaultValue !== null)) {
            throw new \InvalidArgumentException('A missing column default cannot carry a value.');
        }

        if ($defaultSensitive && $defaultValue !== null) {
            throw new \InvalidArgumentException('A sensitive column default must be withheld.');
        }

        if (! $defaultSensitive && $defaultPresent && ($defaultValue === null || strlen($defaultValue) > 64)) {
            throw new \InvalidArgumentException('A disclosed column default must be short.');
        }

        if (! $generated && $generation !== null) {
            throw new \InvalidArgumentException('A column generation type requires a generated column.');
        }

        if ($generation !== null && ! in_array($generation, ['stored', 'virtual'], true)) {
            throw new \InvalidArgumentException('Schema column generation must be stored or virtual.');
        }

        if ($characterLength !== null && ($characterLength < 0 || $characterLength > 16777216)) {
            throw new \InvalidArgumentException('Schema character length is outside the allowed range.');
        }

        if ($numericPrecision !== null && ($numericPrecision < 1 || $numericPrecision > 65)) {
            throw new \InvalidArgumentException('Schema numeric precision is outside the allowed range.');
        }

        if ($numericScale !== null && ($numericPrecision === null || $numericScale < 0 || $numericScale > $numericPrecision)) {
            throw new \InvalidArgumentException('Schema numeric scale is outside the allowed range.');
        }

        if ($json && $typeName !== 'json') {
            throw new \InvalidArgumentException('Only a json column is marked json.');
        }

        if ($position < 1 || $position > 4096) {
            throw new \InvalidArgumentException('Schema column position is outside the allowed range.');
        }
    }
}
