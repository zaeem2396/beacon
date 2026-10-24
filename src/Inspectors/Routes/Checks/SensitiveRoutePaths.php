<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Routes\Checks;

use Beacon\Data\Finding;
use Beacon\Data\FindingStatus;
use Beacon\Data\Observation;
use Beacon\Data\Severity;
use Beacon\Inspectors\Routes\RouteCatalog;
use Beacon\Inspectors\Routes\RouteCheck;

/**
 * Flags URI segments that often belong to operational tools or hidden files.
 *
 * The match is a heuristic. It does not mean the route is exposed, unauthenticated,
 * or vulnerable.
 */
final class SensitiveRoutePaths extends RouteCheck
{
    public const ID = 'sensitive_route_paths';

    private const TITLE = 'Sensitive route paths';

    private const SHOWN = 10;

    /**
     * Compared to whole URI segments, case-insensitively. A segment match
     * avoids treating "storage-report" as "storage".
     */
    private const SEGMENTS = [
        '.env',
        '.git',
        '.htaccess',
        '.htpasswd',
        '.svn',
        '_ignition',
        'adminer',
        'debugbar',
        'horizon',
        'ignition',
        'log-viewer',
        'phpinfo',
        'phpmyadmin',
        'pulse',
        'storage',
        'telescope',
        'vendor',
    ];

    public function evaluate(RouteCatalog $catalog): Finding
    {
        $uris = [];

        foreach ($catalog->routes as $collected) {
            $uri = $collected->route->uri;

            if ($uri !== null && $this->matches($uri)) {
                $uris[$uri] = true;
            }
        }

        $uris = array_keys($uris);
        sort($uris);

        if ($uris === []) {
            return $this->finding(
                self::ID,
                FindingStatus::Pass,
                Severity::None,
                self::TITLE,
                'No disclosed URI uses a segment from the heuristic list of operational or hidden-file paths.',
                [$this->evidence('route.uri', Observation::None)],
                null,
            );
        }

        return $this->finding(
            self::ID,
            FindingStatus::Risk,
            Severity::Low,
            self::TITLE,
            'Heuristic only: these URI patterns contain a segment often used for operational tools or hidden files: ' . $this->listed($uris) . '. This does not mean the route is vulnerable, reachable, or missing authentication.',
            [$this->evidence('route.uri', Observation::Heuristic)],
            'Confirm that each listed route is intended to be registered and is protected by the middleware you expect.',
        );
    }

    private function matches(string $uri): bool
    {
        foreach (explode('/', trim($uri, '/')) as $segment) {
            if ($segment !== '' && in_array(strtolower($segment), self::SEGMENTS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $uris
     */
    private function listed(array $uris): string
    {
        $shown = array_slice($uris, 0, self::SHOWN);
        $text = implode(', ', $shown);
        $remaining = count($uris) - count($shown);

        if ($remaining > 0) {
            $text .= ', and ' . $remaining . ' more';
        }

        return $text;
    }
}
