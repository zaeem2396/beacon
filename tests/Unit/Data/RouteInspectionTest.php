<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Data;

use Beacon\Data\Finding;
use Beacon\Data\FindingEvidence;
use Beacon\Data\FindingStatus;
use Beacon\Data\Observation;
use Beacon\Data\RouteInspection;
use Beacon\Data\RoutePage;
use Beacon\Data\RouteRecord;
use Beacon\Data\Severity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteInspectionTest extends TestCase
{
    #[Test]
    public function it_serializes_a_page_of_routes_and_keeps_null_fields(): void
    {
        $inspection = $this->inspection();
        $data = $inspection->toArray();

        $this->assertSame(['schema_version', 'pagination', 'routes', 'findings'], array_keys($data));
        $this->assertSame(1, $inspection->schemaVersion);
        $this->assertSame(
            ['page', 'per_page', 'total', 'pages', 'count'],
            array_keys($inspection->pagination->toArray()),
        );
        $this->assertSame(
            ['methods', 'uri', 'name', 'domain', 'middleware', 'action', 'framework_generated'],
            array_keys($inspection->routes[0]->toArray()),
        );
        $this->assertNull($inspection->routes[0]->name);
        $this->assertNull($inspection->routes[0]->domain);
        $this->assertNull($inspection->routes[0]->frameworkGenerated);
        $this->assertSame('closure', $inspection->routes[0]->action);
        $this->assertSame(['id', 'status', 'severity', 'title', 'description', 'evidence', 'remediation'], array_keys($inspection->findings[0]->toArray()));
    }

    #[Test]
    public function methods_must_be_in_canonical_order(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RouteRecord(['POST', 'GET'], 'users', null, null, [], 'closure', null);
    }

    #[Test]
    public function the_page_count_must_match_the_total(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RoutePage(1, 50, 2, 5, 0);
    }

    private function inspection(): RouteInspection
    {
        return new RouteInspection(
            new RoutePage(1, 50, 1, 1, 1),
            [new RouteRecord(['GET', 'HEAD'], 'users/{user}', null, null, ['auth'], 'closure', null)],
            [new Finding(
                'duplicate_route_names',
                FindingStatus::Pass,
                Severity::None,
                'Duplicate route names',
                'Every route name is unique.',
                [new FindingEvidence('route.name', Observation::None)],
                null,
            )],
        );
    }
}
