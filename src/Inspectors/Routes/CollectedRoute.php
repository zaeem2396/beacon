<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Routes;

use Beacon\Data\DataObject;
use Beacon\Data\RouteRecord;

/**
 * One route as the analyzer sees it.
 *
 * The public route record does not include excluded middleware. The two flags
 * are classifications, so a raw excluded-middleware value never reaches a finding.
 */
final readonly class CollectedRoute extends DataObject
{
    public function __construct(
        public RouteRecord $route,
        public bool $authenticationMiddlewareExcluded,
        public bool $authenticationMiddlewareUnreadable,
    ) {
    }
}
