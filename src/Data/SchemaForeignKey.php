<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Health\SafeName;
use Beacon\Schema\ReferentialAction;

/**
 * One foreign key, including a composite key when the driver reports every column.
 */
final readonly class SchemaForeignKey extends DataObject
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
        public ReferentialAction $onUpdate,
        public ReferentialAction $onDelete,
    ) {
        if (! SafeName::isIdentifier($name) || ! SafeName::isIdentifier($referencedTable)) {
            throw new \InvalidArgumentException('Schema foreign key names must be plain identifiers.');
        }

        if ($columns === [] || count($columns) !== count($referencedColumns) || count($columns) > 8) {
            throw new \InvalidArgumentException('Schema foreign key columns must match the referenced columns.');
        }

        foreach ([...$columns, ...$referencedColumns] as $column) {
            if (! is_string($column) || ! SafeName::isIdentifier($column)) {
                throw new \InvalidArgumentException('Schema foreign key columns must be plain identifiers.');
            }
        }
    }
}
