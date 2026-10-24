<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Inspectors;

use Beacon\Data\Finding;
use Beacon\Data\RouteRecord;
use Beacon\Inspectors\Routes\Checks\AuthenticationMiddlewareExcluded;
use Beacon\Inspectors\Routes\Checks\DuplicateRouteNames;
use Beacon\Inspectors\Routes\Checks\SensitiveRoutePaths;
use Beacon\Inspectors\Routes\CollectedRoute;
use Beacon\Inspectors\Routes\RouteAnalyzer;
use Beacon\Inspectors\Routes\RouteCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteAnalyzerTest extends TestCase
{
    private const IDS = [
        DuplicateRouteNames::ID,
        SensitiveRoutePaths::ID,
        AuthenticationMiddlewareExcluded::ID,
    ];

    #[Test]
    public function checks_run_in_a_stable_order_and_pass_for_an_ordinary_route(): void
    {
        $analysis = (new RouteAnalyzer())->analyze(new RouteCatalog([
            $this->route('GET', 'users', 'users.index', false, false),
        ]));

        $this->assertInstanceOf(\Beacon\Inspectors\Routes\RouteAnalysis::class, $analysis);
        $this->assertSame(self::IDS, array_map(static fn (Finding $finding): string => $finding->id, $analysis->findings));

        foreach ($analysis->findings as $finding) {
            $this->assertSame('pass', $finding->status->value);
            $this->assertSame('none', $finding->severity->value);
            $this->assertNull($finding->remediation);
        }
    }

    #[Test]
    public function duplicate_names_are_a_risk_and_not_described_as_a_vulnerability(): void
    {
        $finding = $this->finding(new RouteCatalog([
            $this->route('GET', 'a', 'shared', false, false),
            $this->route('POST', 'b', 'shared', false, false),
            $this->route('GET', 'c', null, false, false),
        ]), DuplicateRouteNames::ID);

        $this->assertSame('risk', $finding->status->value);
        $this->assertSame('low', $finding->severity->value);
        $this->assertSame('duplicate', $finding->evidence[0]->observation->value);
        $this->assertStringContainsString('shared', $finding->description);
        $this->assertStringContainsString('not a vulnerability', $finding->description);
    }

    #[Test]
    public function sensitive_paths_match_whole_segments_and_stay_heuristic(): void
    {
        $matched = $this->finding(new RouteCatalog([
            $this->route('GET', 'telescope/requests', 'telescope', false, false),
            $this->route('GET', 'storage-report', 'reports', false, false),
        ]), SensitiveRoutePaths::ID);

        $this->assertSame('risk', $matched->status->value);
        $this->assertSame('heuristic', $matched->evidence[0]->observation->value);
        $this->assertStringContainsString('telescope/requests', $matched->description);
        $this->assertStringNotContainsString('storage-report', $matched->description);
        $this->assertStringContainsString('does not mean the route is vulnerable', $matched->description);
    }

    #[Test]
    public function excluding_authentication_middleware_is_a_risk_without_claiming_the_route_is_open(): void
    {
        $finding = $this->finding(new RouteCatalog([
            $this->route('GET', 'account', 'account', true, false),
        ]), AuthenticationMiddlewareExcluded::ID);

        $this->assertSame('risk', $finding->status->value);
        $this->assertStringContainsString('account', $finding->description);
        $this->assertStringContainsString('does not prove', $finding->description);
    }

    #[Test]
    public function an_unreadable_exclusion_is_unknown_when_nothing_was_confirmed(): void
    {
        $finding = $this->finding(new RouteCatalog([
            $this->route('GET', 'account', 'account', false, true),
        ]), AuthenticationMiddlewareExcluded::ID);

        $this->assertSame('unknown', $finding->status->value);
        $this->assertSame('none', $finding->severity->value);
        $this->assertSame('invalid', $finding->evidence[0]->observation->value);
    }

    #[Test]
    public function a_confirmed_exclusion_stays_a_risk_when_another_entry_is_unreadable(): void
    {
        $finding = $this->finding(new RouteCatalog([
            $this->route('GET', 'account', 'account', true, true),
        ]), AuthenticationMiddlewareExcluded::ID);

        $this->assertSame('risk', $finding->status->value);
        $this->assertStringContainsString('could not be read', $finding->description);
    }

    private function finding(RouteCatalog $catalog, string $id): Finding
    {
        $analysis = (new RouteAnalyzer())->analyze($catalog);
        $this->assertInstanceOf(\Beacon\Inspectors\Routes\RouteAnalysis::class, $analysis);

        foreach ($analysis->findings as $finding) {
            if ($finding->id === $id) {
                return $finding;
            }
        }

        $this->fail('Missing finding ' . $id);
    }

    private function route(string $method, string $uri, ?string $name, bool $excluded, bool $unreadable): CollectedRoute
    {
        $methods = $method === 'GET' ? ['GET', 'HEAD'] : [$method];

        return new CollectedRoute(
            new RouteRecord($methods, $uri, $name, null, [], 'closure', null),
            $excluded,
            $unreadable,
        );
    }
}
