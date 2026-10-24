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
 * Reports routes that explicitly exclude a known authentication middleware alias.
 *
 * Exclusion is evidence about middleware configuration. It is not evidence that
 * the route can be called without authentication, and an unfamiliar middleware
 * alias is never treated as missing authentication.
 */
final class AuthenticationMiddlewareExcluded extends RouteCheck
{
    public const ID = 'authentication_middleware_excluded';

    private const TITLE = 'Authentication middleware excluded';

    private const SHOWN = 10;

    public function evaluate(RouteCatalog $catalog): Finding
    {
        $uris = [];
        $unreadable = false;

        foreach ($catalog->routes as $collected) {
            if ($collected->authenticationMiddlewareUnreadable) {
                $unreadable = true;
            }

            if ($collected->authenticationMiddlewareExcluded) {
                $uris[$collected->route->uri ?? '(uri withheld)'] = true;
            }
        }

        if ($uris === [] && $unreadable) {
            return $this->finding(
                self::ID,
                FindingStatus::Unknown,
                Severity::None,
                self::TITLE,
                'An excluded-middleware entry was not a string, so this check was not evaluated. Controller middleware is not resolved.',
                [$this->evidence('route.middleware', Observation::Invalid)],
                'Express excluded middleware as middleware names. Beacon does not instantiate controllers to discover middleware.',
            );
        }

        if ($uris === []) {
            return $this->finding(
                self::ID,
                FindingStatus::Pass,
                Severity::None,
                self::TITLE,
                'No disclosed route excludes a known authentication middleware alias. Routes without an auth alias are not reported, because a missing alias is not evidence of missing authentication.',
                [$this->evidence('route.middleware', Observation::None)],
                null,
            );
        }

        $uris = array_keys($uris);
        sort($uris);
        $suffix = $unreadable ? ' Some other excluded-middleware entries could not be read.' : '';

        return $this->finding(
            self::ID,
            FindingStatus::Risk,
            Severity::Low,
            self::TITLE,
            'These routes explicitly exclude a known authentication middleware alias: ' . $this->listed($uris) . '.' . $suffix . ' This does not prove the route is reachable without authentication.',
            [$this->evidence('route.middleware', Observation::Present)],
            'Review why the authentication middleware was removed and confirm another guard covers the route.',
        );
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
