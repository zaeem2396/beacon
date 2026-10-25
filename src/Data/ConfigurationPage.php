<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * Pagination for one page of configuration settings.
 *
 * total is the number of allowlisted settings that matched the filter,
 * before this page was sliced.
 */
final readonly class ConfigurationPage extends DataObject
{
    public function __construct(
        public int $page,
        public int $perPage,
        public int $total,
        public int $pages,
        public int $count,
    ) {
        if ($page < 1 || $perPage < 1 || $total < 0 || $pages < 0 || $count < 0) {
            throw new \InvalidArgumentException('Configuration pagination counts cannot be negative.');
        }

        if ($count > $perPage || $count > $total) {
            throw new \InvalidArgumentException('A configuration page cannot be larger than the page size or the total.');
        }

        $expectedPages = $total === 0 ? 0 : (int) ceil($total / $perPage);

        if ($pages !== $expectedPages) {
            throw new \InvalidArgumentException('Configuration page count does not match the total.');
        }
    }
}
