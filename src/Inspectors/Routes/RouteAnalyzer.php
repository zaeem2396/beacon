<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Routes;

use Beacon\Contracts\Analyzer;
use Beacon\Contracts\Data;
use Beacon\Data\Finding;
use Beacon\Inspectors\Routes\Checks\AuthenticationMiddlewareExcluded;
use Beacon\Inspectors\Routes\Checks\DuplicateRouteNames;
use Beacon\Inspectors\Routes\Checks\SensitiveRoutePaths;

/**
 * Runs the route checks in a fixed order.
 *
 * The analyzer sees only the catalog the inspector collected. Adding a check
 * means adding one class to {@see self::checks()} and nothing else.
 *
 * @implements Analyzer<RouteCatalog, RouteAnalysis>
 */
final class RouteAnalyzer implements Analyzer
{
    public function analyze(Data $subject): Data
    {
        if (! $subject instanceof RouteCatalog) {
            throw new \InvalidArgumentException('Route analysis requires a route catalog.');
        }

        return new RouteAnalysis(array_map(
            static fn (RouteCheck $check): Finding => $check->evaluate($subject),
            $this->checks(),
        ));
    }

    /**
     * @return list<RouteCheck>
     */
    public function checks(): array
    {
        return [
            new DuplicateRouteNames(),
            new SensitiveRoutePaths(),
            new AuthenticationMiddlewareExcluded(),
        ];
    }
}
