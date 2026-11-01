<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Health\SafeName;
use Beacon\Schema\ObjectType;

/**
 * One table or view.
 *
 * Detail arrays are empty when the caller did not ask for them. Counts still
 * describe the object. Row estimates are metadata estimates, never a scan.
 */
final readonly class SchemaObject extends DataObject
{
    /**
     * @param list<string> $primaryKey
     * @param list<SchemaColumn> $columns
     * @param list<SchemaForeignKey> $foreignKeys
     * @param list<SchemaIndex> $indexes
     * @param list<SchemaUniqueConstraint> $uniqueConstraints
     */
    public function __construct(
        public string $name,
        public ObjectType $type,
        public ?int $rowEstimate,
        public bool $estimated,
        public int $columnCount,
        public array $primaryKey,
        public int $indexCount,
        public int $foreignKeyCount,
        public array $columns,
        public array $foreignKeys,
        public array $indexes,
        public array $uniqueConstraints,
    ) {
        if (! SafeName::isIdentifier($name)) {
            throw new \InvalidArgumentException('Schema object name must be a plain identifier.');
        }

        if ($rowEstimate !== null && $rowEstimate < 0) {
            throw new \InvalidArgumentException('Schema row estimate cannot be negative.');
        }

        if ($estimated !== ($rowEstimate !== null)) {
            throw new \InvalidArgumentException('A schema row estimate must be marked as an estimate.');
        }

        if ($type === ObjectType::View && $rowEstimate !== null) {
            throw new \InvalidArgumentException('A view does not include a row estimate.');
        }

        if ($columnCount < count($columns) || $indexCount < count($indexes) || $foreignKeyCount < count($foreignKeys)) {
            throw new \InvalidArgumentException('Schema counts cannot be smaller than the returned details.');
        }

        if ($columns !== [] && $columnCount !== count($columns)) {
            throw new \InvalidArgumentException('Schema column count must match the returned columns.');
        }

        if ($indexes !== [] && $indexCount !== count($indexes)) {
            throw new \InvalidArgumentException('Schema index count must match the returned indexes.');
        }

        if ($foreignKeys !== [] && $foreignKeyCount !== count($foreignKeys)) {
            throw new \InvalidArgumentException('Schema foreign key count must match the returned foreign keys.');
        }

        if ($indexes === [] && $uniqueConstraints !== []) {
            throw new \InvalidArgumentException('Unique constraints are returned with the indexes they describe.');
        }

        foreach ($primaryKey as $column) {
            if (! is_string($column) || ! SafeName::isIdentifier($column)) {
                throw new \InvalidArgumentException('Schema primary key columns must be plain identifiers.');
            }
        }

        foreach ($columns as $column) {
            if (! $column instanceof SchemaColumn) {
                throw new \InvalidArgumentException('Schema columns must be column records.');
            }
        }

        foreach ($foreignKeys as $foreignKey) {
            if (! $foreignKey instanceof SchemaForeignKey) {
                throw new \InvalidArgumentException('Schema foreign keys must be foreign key records.');
            }
        }

        foreach ($indexes as $index) {
            if (! $index instanceof SchemaIndex) {
                throw new \InvalidArgumentException('Schema indexes must be index records.');
            }
        }

        foreach ($uniqueConstraints as $constraint) {
            if (! $constraint instanceof SchemaUniqueConstraint) {
                throw new \InvalidArgumentException('Schema unique constraints must be unique constraint records.');
            }
        }
    }
}
