<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Health\SafeName;

/**
 * A unique constraint that is not the primary key.
 *
 * The columns are the same columns as the unique index. Uniqueness is not
 * inferred from the constraint name.
 */
final readonly class SchemaUniqueConstraint extends DataObject
{
    /**
     * @param list<string> $columns
     */
    public function __construct(
        public string $name,
        public array $columns,
    ) {
        if (! SafeName::isIdentifier($name) || $name === 'primary' || $columns === [] || count($columns) > 16) {
            throw new \InvalidArgumentException('Schema unique constraint must name a non-primary identifier and its columns.');
        }

        foreach ($columns as $column) {
            if (! is_string($column) || ! SafeName::isIdentifier($column)) {
                throw new \InvalidArgumentException('Schema unique constraint columns must be plain identifiers.');
            }
        }
    }
}
