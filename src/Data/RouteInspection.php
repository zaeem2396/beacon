<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * One page of registered routes plus findings about the whole route table.
 *
 * Findings are not limited to the current page. A duplicate name on another
 * page is still a duplicate.
 */
final readonly class RouteInspection extends DataObject
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    /**
     * @param list<RouteRecord> $routes
     * @param list<Finding> $findings
     */
    public function __construct(
        public RoutePage $pagination,
        public array $routes,
        public array $findings,
    ) {
        if ($pagination->count !== count($routes)) {
            throw new \InvalidArgumentException('Route pagination count must match the returned routes.');
        }

        foreach ($routes as $route) {
            if (! $route instanceof RouteRecord) {
                throw new \InvalidArgumentException('Route inspection routes must be route records.');
            }
        }

        foreach ($findings as $finding) {
            if (! $finding instanceof Finding) {
                throw new \InvalidArgumentException('Route inspection findings must be findings.');
            }
        }

        $this->schemaVersion = self::SCHEMA_VERSION;
    }
}
