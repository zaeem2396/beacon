<?php

declare(strict_types=1);

namespace Beacon\Risk;

/**
 * Whether a table was visible in schema metadata.
 *
 * rowEstimate comes from information_schema and is not a count of rows read.
 */
final readonly class TableSnapshot
{
    /**
     * @param array<string, ColumnSnapshot> $columns
     */
    public function __construct(
        public bool $exists,
        public ?int $rowEstimate,
        public array $columns,
    ) {
    }

    public function column(?string $name): ?ColumnSnapshot
    {
        if ($name === null) {
            return null;
        }

        return $this->columns[$name] ?? null;
    }
}
