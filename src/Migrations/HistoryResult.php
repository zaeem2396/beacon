<?php

declare(strict_types=1);

namespace Beacon\Migrations;

/**
 * Rows read from the migration repository.
 *
 * available is false when the table is absent. empty is true only when the
 * table was read and had no rows.
 */
final readonly class HistoryResult
{
    /**
     * @param array<string, int> $batches
     */
    public function __construct(
        public bool $available,
        public bool $empty,
        public array $batches,
        public bool $withheld,
    ) {
    }

    public static function missing(): self
    {
        return new self(false, false, [], false);
    }
}
