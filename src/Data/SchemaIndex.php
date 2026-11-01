<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Health\SafeName;

/**
 * One index, in column order.
 *
 * unique comes from the database metadata, not from the index name.
 * expression is true when the index has a component that is not a plain column.
 * The expression text is not returned.
 */
final readonly class SchemaIndex extends DataObject
{
    /**
     * @param list<string> $columns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique,
        public bool $primary,
        public ?string $type,
        public bool $expression,
    ) {
        if (! SafeName::isIdentifier($name)) {
            throw new \InvalidArgumentException('Schema index name must be a plain identifier.');
        }

        if ($primary && ! $unique) {
            throw new \InvalidArgumentException('A primary index is unique.');
        }

        if (! $expression && $columns === []) {
            throw new \InvalidArgumentException('A column index must name its columns.');
        }

        foreach ($columns as $column) {
            if (! is_string($column) || ! SafeName::isIdentifier($column)) {
                throw new \InvalidArgumentException('Schema index columns must be plain identifiers.');
            }
        }

        if ($type !== null && ! in_array($type, ['btree', 'hash', 'fulltext', 'spatial'], true)) {
            throw new \InvalidArgumentException('Schema index type must be a recognised value.');
        }
    }
}
