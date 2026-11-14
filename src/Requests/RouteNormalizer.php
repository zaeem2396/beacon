<?php

declare(strict_types=1);

namespace Beacon\Requests;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Produces stable, non-secret route identifiers for grouping.
 */
final class RouteNormalizer
{
    public const UNMATCHED = 'unmatched';

    public const MAX = 191;

    public function name(?Route $route): ?string
    {
        if ($route === null) {
            return null;
        }

        $name = $route->getName();

        if (! is_string($name) || $name === '') {
            return null;
        }

        $name = substr($name, 0, self::MAX);

        return preg_match('/^[A-Za-z0-9_.:-]{1,191}$/', $name) === 1 ? $name : null;
    }

    public function uri(Request $request, ?Route $route): string
    {
        if ($route !== null) {
            $uri = $route->uri();

            if (is_string($uri) && $uri !== '') {
                $uri = '/' . ltrim($uri, '/');
                $uri = substr($uri, 0, self::MAX);

                if (preg_match('#^[A-Za-z0-9_./{}:-]{1,191}$#', $uri) === 1) {
                    return $uri;
                }
            }
        }

        return self::UNMATCHED;
    }

    public function action(?Route $route): ?string
    {
        if ($route === null) {
            return null;
        }

        $action = $route->getActionName();

        if (! is_string($action) || $action === '' || $action === 'Closure') {
            return $action === 'Closure' ? 'Closure' : null;
        }

        $action = substr($action, 0, self::MAX);

        return preg_match('/^[A-Za-z0-9_\\\\@.-]{1,191}$/', $action) === 1 ? $action : null;
    }

    public function method(Request $request): string
    {
        $method = strtoupper($request->getMethod());

        return preg_match('/^[A-Z]{1,16}$/', $method) === 1 ? $method : 'UNKNOWN';
    }
}
