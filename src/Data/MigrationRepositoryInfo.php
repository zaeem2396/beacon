<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * Whether the migration history table could be read.
 *
 * available is false when the table is missing. empty is true only when
 * the table exists and contains no rows. Those are different states.
 * A missing table is never created by inspection.
 */
final readonly class MigrationRepositoryInfo extends DataObject
{
    public function __construct(
        public bool $available,
        public bool $empty,
        public ?string $table,
    ) {
        if ($empty && ! $available) {
            throw new \InvalidArgumentException('A missing migration repository is not an empty history.');
        }

        if ($table !== null && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $table) !== 1) {
            throw new \InvalidArgumentException('Migration repository table must be a plain identifier.');
        }
    }
}
