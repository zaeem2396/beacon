<?php

declare(strict_types=1);

namespace Beacon\Comparison;

use Beacon\Data\DataObject;
use Beacon\Data\SchemaObject;
use Beacon\Health\SafeName;
use Beacon\Schema\ObjectType;

/**
 * One table or view in a schema snapshot.
 *
 * Row estimates are omitted. They change without a structural change.
 * View definitions are not stored.
 */
final readonly class SnapshotObject extends DataObject
{
    /**
     * @param list<SnapshotColumn> $columns
     * @param list<string> $primaryColumns
     * @param list<SnapshotIndex> $indexes
     * @param list<SnapshotForeignKey> $references
     */
    public function __construct(
        public string $name,
        public ObjectType $type,
        public array $columns,
        public array $primaryColumns,
        public array $indexes,
        public array $references,
    ) {
        if (! SafeName::isIdentifier($name)) {
            throw new \InvalidArgumentException('Snapshot object name must be a plain identifier.');
        }

        if ($type === ObjectType::View && ($primaryColumns !== [] || $indexes !== [] || $references !== [])) {
            throw new \InvalidArgumentException('A snapshot view does not store keys or indexes.');
        }

        if (count($columns) > 4096 || count($indexes) > 256 || count($references) > 256 || count($primaryColumns) > 16) {
            throw new \InvalidArgumentException('A snapshot object has too many parts.');
        }

        $names = [];

        foreach ($columns as $column) {
            if (! $column instanceof SnapshotColumn || isset($names[$column->name])) {
                throw new \InvalidArgumentException('Snapshot columns must be unique column records.');
            }

            $names[$column->name] = true;
        }

        foreach ($primaryColumns as $column) {
            if (! is_string($column) || ! SafeName::isIdentifier($column)) {
                throw new \InvalidArgumentException('Snapshot primary key columns must be plain identifiers.');
            }
        }

        $indexNames = [];

        foreach ($indexes as $index) {
            if (! $index instanceof SnapshotIndex || isset($indexNames[$index->name])) {
                throw new \InvalidArgumentException('Snapshot indexes must be unique index records.');
            }

            $indexNames[$index->name] = true;
        }

        $keyNames = [];

        foreach ($references as $foreignKey) {
            if (! $foreignKey instanceof SnapshotForeignKey || isset($keyNames[$foreignKey->name])) {
                throw new \InvalidArgumentException('Snapshot foreign keys must be unique foreign key records.');
            }

            $keyNames[$foreignKey->name] = true;
        }
    }

    public static function fromSchema(SchemaObject $object): self
    {
        $columns = [];

        foreach ($object->columns as $column) {
            $columns[] = SnapshotColumn::fromSchema($column);
        }

        usort($columns, static fn (SnapshotColumn $left, SnapshotColumn $right): int => [$left->position, $left->name] <=> [$right->position, $right->name]);

        $indexes = [];

        foreach ($object->indexes as $index) {
            if (! $index->primary) {
                $indexes[] = SnapshotIndex::fromSchema($index);
            }
        }

        usort($indexes, static fn (SnapshotIndex $left, SnapshotIndex $right): int => $left->name <=> $right->name);

        $references = [];

        foreach ($object->foreignKeys as $foreignKey) {
            $references[] = SnapshotForeignKey::fromSchema($foreignKey);
        }

        usort($references, static fn (SnapshotForeignKey $left, SnapshotForeignKey $right): int => $left->name <=> $right->name);

        return new self(
            $object->name,
            $object->type,
            $columns,
            $object->type === ObjectType::View ? [] : $object->primaryKey,
            $object->type === ObjectType::View ? [] : $indexes,
            $object->type === ObjectType::View ? [] : $references,
        );
    }

    public function structureSignature(): string
    {
        $parts = [];

        foreach ($this->columns as $column) {
            $parts[] = $column->name . '=' . $column->signature();
        }

        sort($parts);

        return implode(';', $parts);
    }
}
