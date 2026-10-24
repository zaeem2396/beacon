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
 * Reports route names that are registered more than once.
 *
 * A duplicate name is a registration fact. It is not evidence that a route
 * is vulnerable.
 */
final class DuplicateRouteNames extends RouteCheck
{
    public const ID = 'duplicate_route_names';

    private const TITLE = 'Duplicate route names';

    private const SHOWN = 10;

    public function evaluate(RouteCatalog $catalog): Finding
    {
        $counts = [];

        foreach ($catalog->routes as $collected) {
            $name = $collected->route->name;

            if ($name !== null) {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        $duplicates = array_keys(array_filter($counts, static fn (int $count): bool => $count > 1));
        sort($duplicates);

        if ($duplicates === []) {
            return $this->finding(
                self::ID,
                FindingStatus::Pass,
                Severity::None,
                self::TITLE,
                'Every disclosed route name is unique. Unnamed routes are not compared.',
                [$this->evidence('route.name', Observation::None)],
                null,
            );
        }

        return $this->finding(
            self::ID,
            FindingStatus::Risk,
            Severity::Low,
            self::TITLE,
            'These route names are registered more than once: ' . $this->listed($duplicates) . '. Laravel resolves a name to one of the routes. This is not a vulnerability finding.',
            [$this->evidence('route.name', Observation::Duplicate)],
            'Give each named route a unique name.',
        );
    }

    /**
     * @param list<string> $names
     */
    private function listed(array $names): string
    {
        $shown = array_slice($names, 0, self::SHOWN);
        $text = implode(', ', $shown);
        $remaining = count($names) - count($shown);

        if ($remaining > 0) {
            $text .= ', and ' . $remaining . ' more';
        }

        return $text;
    }
}
