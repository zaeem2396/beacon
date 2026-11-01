<?php

declare(strict_types=1);

namespace Beacon\Comparison;

use Beacon\Data\DataObject;
use Beacon\Data\SchemaIndex;
use Beacon\Health\SafeName;

/**
 * One non-primary index. Primary keys are stored on the table.
 *
 * expression means a component was not a plain column. The expression text is not stored.
 */
final readonly class SnapshotIndex extends DataObject
{
    /**
     * @param list<string> $columns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique,
        public ?string $type,
        public bool $expression,
    ) {
        if (! SafeName::isIdentifier($name)) {
            throw new \InvalidArgumentException('Snapshot index name must be a plain identifier.');
        }

        if (! $expression && $columns === []) {
            throw new \InvalidArgumentException('A column index must name its columns.');
        }

        if (count($columns) > 16) {
            throw new \InvalidArgumentException('A snapshot index has too many columns.');
        }

        foreach ($columns as $column) {
            if (! is_string($column) || ! SafeName::isIdentifier($column)) {
                throw new \InvalidArgumentException('Snapshot index columns must be plain identifiers.');
            }
        }

        if ($type !== null && ! in_array($type, ['btree', 'hash', 'fulltext', 'spatial'], true)) {
            throw new \InvalidArgumentException('Snapshot index type must be a recognised value.');
        }
    }

    public static function fromSchema(SchemaIndex $index): self
    {
        return new self($index->name, $index->columns, $index->unique, $index->type, $index->expression);
    }
}
