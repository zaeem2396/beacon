<?php

declare(strict_types=1);

namespace Beacon\Inspectors;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Data\RouteInspection;
use Beacon\Data\RoutePage;
use Beacon\Data\RouteRecord;
use Beacon\Inspectors\Routes\CollectedRoute;
use Beacon\Inspectors\Routes\RouteAnalysis;
use Beacon\Inspectors\Routes\RouteAnalyzer;
use Beacon\Inspectors\Routes\RouteCatalog;
use Beacon\Inspectors\Routes\RouteNormalizer;
use Beacon\Inspectors\Routes\RouteQuery;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Collects registered routes and evaluates them with {@see RouteAnalyzer}.
 *
 * The router is only enumerated. Handlers are not dispatched, controllers are
 * not constructed, and controller middleware is not resolved. framework_generated
 * is null: route metadata does not reliably say whether the framework registered
 * the route.
 *
 * @implements Inspector<RouteInspection>
 */
final class RouteInspector implements Inspector
{
    public const NAME = 'routes';

    public function __construct(
        private readonly Router $router,
        private readonly RouteNormalizer $normalizer,
        private readonly RouteAnalyzer $analyzer,
    ) {
    }

    public function capability(): Capability
    {
        return InspectionCapability::Routes->capability();
    }

    public function inspect(array $parameters = []): Data
    {
        $query = RouteQuery::from($parameters);
        $collected = [];
        $routes = $this->router->getRoutes()->getRoutes();

        if (! is_array($routes)) {
            throw new \RuntimeException('The route collection could not be read.');
        }

        foreach ($routes as $route) {
            if (! $route instanceof Route) {
                continue;
            }

            $record = $this->normalizer->collect($route);

            if ($record !== null) {
                $collected[] = $record;
            }
        }

        $catalog = new RouteCatalog($collected);
        $analysis = $this->analyzer->analyze($catalog);

        if (! $analysis instanceof RouteAnalysis) {
            throw new \RuntimeException('Route analysis did not return a route analysis.');
        }

        $page = $this->page($collected, $query);

        return new RouteInspection(
            new RoutePage($query->page, $query->limit, count($page['matched']), $page['pages'], count($page['routes'])),
            $page['routes'],
            $analysis->findings,
        );
    }

    /**
     * @param list<CollectedRoute> $collected
     *
     * @return array{matched: list<RouteRecord>, routes: list<RouteRecord>, pages: int}
     */
    private function page(array $collected, RouteQuery $query): array
    {
        $matched = [];

        foreach ($collected as $index => $record) {
            if ($query->matches($record->route)) {
                $matched[] = ['route' => $record->route, 'index' => $index];
            }
        }

        usort($matched, static fn (array $left, array $right): int => [
                $left['route']->uri ?? '',
                implode(',', $left['route']->methods),
                $left['route']->name ?? '',
                $left['route']->domain ?? '',
                $left['route']->action ?? '',
                $left['index'],
            ] <=> [
                $right['route']->uri ?? '',
                implode(',', $right['route']->methods),
                $right['route']->name ?? '',
                $right['route']->domain ?? '',
                $right['route']->action ?? '',
                $right['index'],
            ]);

        $routes = array_map(static fn (array $item): RouteRecord => $item['route'], $matched);
        $total = count($routes);
        $pages = $total === 0 ? 0 : (int) ceil($total / $query->limit);
        $slice = array_values(array_slice($routes, ($query->page - 1) * $query->limit, $query->limit));

        return ['matched' => $routes, 'routes' => $slice, 'pages' => $pages];
    }
}
