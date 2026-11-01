<?php

declare(strict_types=1);

namespace Beacon\Comparison;

use Beacon\Data\DataObject;
use Beacon\Data\SchemaForeignKey;
use Beacon\Health\SafeName;
use Beacon\Schema\ReferentialAction;

/**
 * One foreign key. Referential actions are the database rules, not inferred names.
 */
final readonly class SnapshotForeignKey extends DataObject
{
    /**
     * @param list<string> $columns
     * @param list<string> $referencedColumns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public string $referencedTable,
        public array $referencedColumns,
        public string $onUpdate,
        public string $onDelete,
    ) {
        if (! SafeName::isIdentifier($name) || ! SafeName::isIdentifier($referencedTable)) {
            throw new \InvalidArgumentException('Snapshot foreign key names must be plain identifiers.');
        }

        if ($columns === [] || count($columns) !== count($referencedColumns) || count($columns) > 8) {
            throw new \InvalidArgumentException('Snapshot foreign key columns must match the referenced columns.');
        }

        foreach ([...$columns, ...$referencedColumns] as $column) {
            if (! is_string($column) || ! SafeName::isIdentifier($column)) {
                throw new \InvalidArgumentException('Snapshot foreign key columns must be plain identifiers.');
            }
        }

        if (ReferentialAction::tryFrom($onUpdate) === null || ReferentialAction::tryFrom($onDelete) === null) {
            throw new \InvalidArgumentException('Snapshot foreign key actions must be recognised rules.');
        }
    }

    public static function fromSchema(SchemaForeignKey $foreignKey): self
    {
        return new self(
            $foreignKey->name,
            $foreignKey->columns,
            $foreignKey->referencedTable,
            $foreignKey->referencedColumns,
            $foreignKey->onUpdate->value,
            $foreignKey->onDelete->value,
        );
    }
}
