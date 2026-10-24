<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Routes;

use Beacon\Data\RouteRecord;
use Illuminate\Routing\Route;

/**
 * Reads one Laravel route without dispatching it or resolving its controller.
 */
final class RouteNormalizer
{
    public function collect(Route $route): ?CollectedRoute
    {
        $methods = RouteText::methods($route->methods());

        if ($methods === []) {
            return null;
        }

        $action = $route->getActionName();

        if ($action === 'Closure') {
            $action = 'closure';
        }

        $middleware = $route->middleware();
        $exclusion = RouteText::authenticationExclusion($route->getAction('excluded_middleware'));

        return new CollectedRoute(
            new RouteRecord(
                $methods,
                RouteText::uri($route->uri()),
                $this->optional($route->getName(), RouteText::name(...)),
                $this->optional($route->getDomain(), RouteText::domain(...)),
                RouteText::middleware(is_array($middleware) ? $middleware : []),
                RouteText::action($action),
                null,
            ),
            $exclusion['excluded'],
            $exclusion['unreadable'],
        );
    }

    /**
     * @param callable(string): ?string $normalize
     */
    private function optional(mixed $value, callable $normalize): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $normalize($value);
    }
}
