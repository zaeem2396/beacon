<?php

declare(strict_types=1);

namespace Beacon\Comparison;

use Beacon\Data\DataObject;
use Beacon\Data\SchemaColumn;
use Beacon\Health\SafeName;

/**
 * Column metadata copied from schema inspection. Values from the table are not included.
 */
final readonly class SnapshotColumn extends DataObject
{
    public function __construct(
        public string $name,
        public string $typeName,
        public string $normalizedType,
        public bool $nullable,
        public bool $defaultPresent,
        public bool $defaultSensitive,
        public ?string $defaultValue,
        public bool $autoIncrement,
        public bool $generated,
        public ?string $generation,
        public ?int $characterLength,
        public ?int $numericPrecision,
        public ?int $numericScale,
        public bool $unsigned,
        public bool $booleanLike,
        public bool $json,
        public int $position,
    ) {
        if (! SafeName::isIdentifier($name)) {
            throw new \InvalidArgumentException('Snapshot column name must be a plain identifier.');
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $typeName) !== 1) {
            throw new \InvalidArgumentException('Snapshot column type must be a plain type name.');
        }

        $normalized = self::normalize($typeName, $json, $booleanLike);

        if ($normalizedType !== $normalized) {
            throw new \InvalidArgumentException('Snapshot normalized type must follow the column flags.');
        }

        if (! $defaultPresent && ($defaultSensitive || $defaultValue !== null)) {
            throw new \InvalidArgumentException('A missing snapshot default cannot carry a value.');
        }

        if ($defaultSensitive && $defaultValue !== null) {
            throw new \InvalidArgumentException('A sensitive snapshot default must be withheld.');
        }

        if (! $defaultSensitive && $defaultPresent && ($defaultValue === null || ! ComparisonText::isDefault($defaultValue))) {
            throw new \InvalidArgumentException('A disclosed snapshot default must be a short literal.');
        }

        if (! $generated && $generation !== null) {
            throw new \InvalidArgumentException('A snapshot generation type requires a generated column.');
        }

        if ($generation !== null && ! in_array($generation, ['stored', 'virtual'], true)) {
            throw new \InvalidArgumentException('Snapshot column generation must be stored or virtual.');
        }

        if ($characterLength !== null && ($characterLength < 0 || $characterLength > 16777216)) {
            throw new \InvalidArgumentException('Snapshot character length is outside the allowed range.');
        }

        if ($numericPrecision !== null && ($numericPrecision < 1 || $numericPrecision > 65)) {
            throw new \InvalidArgumentException('Snapshot numeric precision is outside the allowed range.');
        }

        if ($numericScale !== null && ($numericPrecision === null || $numericScale < 0 || $numericScale > $numericPrecision)) {
            throw new \InvalidArgumentException('Snapshot numeric scale is outside the allowed range.');
        }

        if ($json && $typeName !== 'json') {
            throw new \InvalidArgumentException('Only a json snapshot column is marked json.');
        }

        if ($position < 1 || $position > 4096) {
            throw new \InvalidArgumentException('Snapshot column position is outside the allowed range.');
        }
    }

    public static function fromSchema(SchemaColumn $column): self
    {
        return new self(
            $column->name,
            $column->typeName,
            self::normalize($column->typeName, $column->json, $column->booleanLike),
            $column->nullable,
            $column->defaultPresent,
            $column->defaultSensitive,
            $column->defaultValue,
            $column->autoIncrement,
            $column->generated,
            $column->generation,
            $column->characterLength,
            $column->numericPrecision,
            $column->numericScale,
            $column->unsigned,
            $column->booleanLike,
            $column->json,
            $column->position,
        );
    }

    public static function normalize(string $typeName, bool $json, bool $booleanLike): string
    {
        if ($booleanLike) {
            return 'boolean';
        }

        if ($json) {
            return 'json';
        }

        return $typeName;
    }

    public function signature(): string
    {
        return implode('|', [
            $this->typeName,
            $this->normalizedType,
            $this->nullable ? '1' : '0',
            $this->number($this->characterLength),
            $this->number($this->numericPrecision),
            $this->number($this->numericScale),
            $this->unsigned ? '1' : '0',
            $this->defaultPresent ? '1' : '0',
            $this->defaultSensitive ? '1' : '0',
            $this->defaultValue ?? '',
            $this->autoIncrement ? '1' : '0',
            $this->generated ? '1' : '0',
            $this->generation ?? '',
        ]);
    }

    private function number(?int $value): string
    {
        return $value === null ? '' : (string) $value;
    }
}
