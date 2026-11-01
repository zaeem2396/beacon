<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * Pagination for one page of migrations.
 */
final readonly class MigrationPage extends DataObject
{
    public function __construct(
        public int $page,
        public int $perPage,
        public int $total,
        public int $pages,
        public int $count,
    ) {
        if ($page < 1 || $perPage < 1 || $total < 0 || $pages < 0 || $count < 0) {
            throw new \InvalidArgumentException('Migration pagination counts cannot be negative.');
        }

        if ($count > $perPage || $count > $total) {
            throw new \InvalidArgumentException('A migration page cannot be larger than the page size or the total.');
        }

        $expectedPages = $total === 0 ? 0 : (int) ceil($total / $perPage);

        if ($pages !== $expectedPages) {
            throw new \InvalidArgumentException('Migration page count does not match the total.');
        }
    }
}
