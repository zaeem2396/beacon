<?php

declare(strict_types=1);

namespace Beacon\Comparison;

use Beacon\Exceptions\InvalidSnapshotException;
use Beacon\Health\SafeName;
use Beacon\Schema\ObjectType;
use Beacon\Schema\ReferentialAction;

/**
 * Loads a snapshot from JSON.
 *
 * The decoder is json_decode with a depth limit. PHP unserialize is never used.
 * The decoded value is checked field by field and then built with the snapshot
 * constructors. Unknown shapes are rejected.
 */
final class SnapshotLoader
{
    public const MAX_BYTES = 524288;

    public function load(string $json): SnapshotDocument
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw InvalidSnapshotException::tooLarge();
        }

        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw InvalidSnapshotException::malformed();
        }

        if (! is_array($decoded)) {
            throw InvalidSnapshotException::malformed();
        }

        $payload = $decoded['snapshot'] ?? $decoded;

        if (! is_array($payload)) {
            throw InvalidSnapshotException::malformed();
        }

        return $this->document($payload);
    }

    /**
     * @param array<mixed> $payload
     */
    private function document(array $payload): SnapshotDocument
    {
        $this->keys($payload, ['format_version', 'metadata', 'objects']);
        $version = $payload['format_version'] ?? null;

        if ($version !== SnapshotDocument::FORMAT_VERSION) {
            throw InvalidSnapshotException::version();
        }

        $metadata = $payload['metadata'] ?? null;
        $objects = $payload['objects'] ?? null;

        if (! is_array($metadata) || ! is_array($objects) || ! array_is_list($objects)) {
            throw InvalidSnapshotException::shape();
        }

        if (count($objects) > SnapshotDocument::MAX_OBJECTS) {
            throw InvalidSnapshotException::tooLarge();
        }

        $built = [];

        foreach ($objects as $object) {
            if (! is_array($object)) {
                throw InvalidSnapshotException::shape();
            }

            $built[] = $this->object($object);
        }

        try {
            return new SnapshotDocument($this->metadata($metadata), $built);
        } catch (\InvalidArgumentException) {
            throw InvalidSnapshotException::shape();
        }
    }

    /**
     * @param array<mixed> $metadata
     */
    private function metadata(array $metadata): SnapshotMetadata
    {
        $this->keys($metadata, [
            'beacon_version',
            'laravel_version',
            'driver',
            'server_version',
            'database',
            'source',
            'identifier',
            'created_at',
            'complete',
            'columns_included',
            'indexes_included',
            'references_included',
            'views_included',
        ]);

        $driver = $metadata['driver'] ?? null;

        if ($driver !== null && ! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw InvalidSnapshotException::driver();
        }

        try {
            return new SnapshotMetadata(
                $this->version($metadata, 'beacon_version'),
                $this->version($metadata, 'laravel_version'),
                is_string($driver) ? $driver : null,
                $this->nullableString($metadata, 'server_version'),
                $this->nullableString($metadata, 'database'),
                $this->string($metadata, 'source'),
                $this->string($metadata, 'identifier'),
                $this->string($metadata, 'created_at'),
                $this->bool($metadata, 'complete'),
                $this->bool($metadata, 'columns_included'),
                $this->bool($metadata, 'indexes_included'),
                $this->bool($metadata, 'references_included'),
                $this->bool($metadata, 'views_included'),
            );
        } catch (\InvalidArgumentException) {
            throw InvalidSnapshotException::shape();
        }
    }

    /**
     * @param array<mixed> $object
     */
    private function object(array $object): SnapshotObject
    {
        $this->keys($object, ['name', 'type', 'columns', 'primary_columns', 'indexes', 'references']);
        $type = ObjectType::tryFrom(is_string($object['type'] ?? null) ? $object['type'] : '');

        if ($type === null) {
            throw InvalidSnapshotException::shape();
        }

        try {
            return new SnapshotObject(
                $this->string($object, 'name'),
                $type,
                $this->columns($object['columns'] ?? null),
                $this->names($object['primary_columns'] ?? null),
                $this->indexes($object['indexes'] ?? null),
                $this->references($object['references'] ?? null),
            );
        } catch (\InvalidArgumentException) {
            throw InvalidSnapshotException::shape();
        }
    }

    /**
     * @return list<SnapshotColumn>
     */
    private function columns(mixed $columns): array
    {
        if (! is_array($columns) || ! array_is_list($columns)) {
            throw InvalidSnapshotException::shape();
        }

        $built = [];

        foreach ($columns as $column) {
            if (! is_array($column)) {
                throw InvalidSnapshotException::shape();
            }

            $this->keys($column, [
                'name',
                'type_name',
                'normalized_type',
                'nullable',
                'default_present',
                'default_sensitive',
                'default_value',
                'auto_increment',
                'generated',
                'generation',
                'character_length',
                'numeric_precision',
                'numeric_scale',
                'unsigned',
                'boolean_like',
                'json',
                'position',
            ]);

            try {
                $built[] = new SnapshotColumn(
                    $this->string($column, 'name'),
                    $this->string($column, 'type_name'),
                    $this->string($column, 'normalized_type'),
                    $this->bool($column, 'nullable'),
                    $this->bool($column, 'default_present'),
                    $this->bool($column, 'default_sensitive'),
                    $this->nullableString($column, 'default_value'),
                    $this->bool($column, 'auto_increment'),
                    $this->bool($column, 'generated'),
                    $this->nullableString($column, 'generation'),
                    $this->nullableInt($column, 'character_length'),
                    $this->nullableInt($column, 'numeric_precision'),
                    $this->nullableInt($column, 'numeric_scale'),
                    $this->bool($column, 'unsigned'),
                    $this->bool($column, 'boolean_like'),
                    $this->bool($column, 'json'),
                    $this->int($column, 'position'),
                );
            } catch (\InvalidArgumentException) {
                throw InvalidSnapshotException::shape();
            }
        }

        return $built;
    }

    /**
     * @return list<SnapshotIndex>
     */
    private function indexes(mixed $indexes): array
    {
        if (! is_array($indexes) || ! array_is_list($indexes)) {
            throw InvalidSnapshotException::shape();
        }

        $built = [];

        foreach ($indexes as $index) {
            if (! is_array($index)) {
                throw InvalidSnapshotException::shape();
            }

            $this->keys($index, ['name', 'columns', 'unique', 'type', 'expression']);

            try {
                $built[] = new SnapshotIndex(
                    $this->string($index, 'name'),
                    $this->names($index['columns'] ?? null),
                    $this->bool($index, 'unique'),
                    $this->nullableString($index, 'type'),
                    $this->bool($index, 'expression'),
                );
            } catch (\InvalidArgumentException) {
                throw InvalidSnapshotException::shape();
            }
        }

        return $built;
    }

    /**
     * @return list<SnapshotForeignKey>
     */
    private function references(mixed $references): array
    {
        if (! is_array($references) || ! array_is_list($references)) {
            throw InvalidSnapshotException::shape();
        }

        $built = [];

        foreach ($references as $foreignKey) {
            if (! is_array($foreignKey)) {
                throw InvalidSnapshotException::shape();
            }

            $this->keys($foreignKey, ['name', 'columns', 'referenced_table', 'referenced_columns', 'on_update', 'on_delete']);
            $onUpdate = $this->string($foreignKey, 'on_update');
            $onDelete = $this->string($foreignKey, 'on_delete');

            if (ReferentialAction::tryFrom($onUpdate) === null || ReferentialAction::tryFrom($onDelete) === null) {
                throw InvalidSnapshotException::shape();
            }

            try {
                $built[] = new SnapshotForeignKey(
                    $this->string($foreignKey, 'name'),
                    $this->names($foreignKey['columns'] ?? null),
                    $this->string($foreignKey, 'referenced_table'),
                    $this->names($foreignKey['referenced_columns'] ?? null),
                    $onUpdate,
                    $onDelete,
                );
            } catch (\InvalidArgumentException) {
                throw InvalidSnapshotException::shape();
            }
        }

        return $built;
    }

    /**
     * @return list<string>
     */
    private function names(mixed $names): array
    {
        if (! is_array($names) || ! array_is_list($names)) {
            throw InvalidSnapshotException::shape();
        }

        $built = [];

        foreach ($names as $name) {
            if (! is_string($name) || ! SafeName::isIdentifier($name)) {
                throw InvalidSnapshotException::shape();
            }

            $built[] = $name;
        }

        return $built;
    }

    /**
     * @param array<mixed> $values
     * @param list<string> $expected
     */
    private function keys(array $values, array $expected): void
    {
        $keys = array_keys($values);
        sort($keys);
        $wanted = $expected;
        sort($wanted);

        if ($keys !== $wanted) {
            throw InvalidSnapshotException::shape();
        }
    }

    /**
     * @param array<mixed> $values
     */
    private function string(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (! is_string($value)) {
            throw InvalidSnapshotException::shape();
        }

        return $value;
    }

    /**
     * @param array<mixed> $values
     */
    private function version(array $values, string $key): string
    {
        $value = $this->string($values, $key);

        if (! ComparisonText::isVersion($value)) {
            throw InvalidSnapshotException::shape();
        }

        return $value;
    }

    /**
     * @param array<mixed> $values
     */
    private function nullableString(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw InvalidSnapshotException::shape();
        }

        return $value;
    }

    /**
     * @param array<mixed> $values
     */
    private function bool(array $values, string $key): bool
    {
        $value = $values[$key] ?? null;

        if (! is_bool($value)) {
            throw InvalidSnapshotException::shape();
        }

        return $value;
    }

    /**
     * @param array<mixed> $values
     */
    private function int(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        if (! is_int($value)) {
            throw InvalidSnapshotException::shape();
        }

        return $value;
    }

    /**
     * @param array<mixed> $values
     */
    private function nullableInt(array $values, string $key): ?int
    {
        $value = $values[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_int($value)) {
            throw InvalidSnapshotException::shape();
        }

        return $value;
    }
}
