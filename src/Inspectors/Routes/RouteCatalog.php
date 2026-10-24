<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Routes;

use Beacon\Data\DataObject;

/**
 * Every route the inspector collected, before filtering and pagination.
 */
final readonly class RouteCatalog extends DataObject
{
    /**
     * @param list<CollectedRoute> $routes
     */
    public function __construct(
        public array $routes,
    ) {
        foreach ($routes as $route) {
            if (! $route instanceof CollectedRoute) {
                throw new \InvalidArgumentException('A route catalog contains only collected routes.');
            }
        }
    }
}
