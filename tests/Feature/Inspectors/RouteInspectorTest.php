<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Data\Finding;
use Beacon\Data\RouteInspection;
use Beacon\Data\RouteRecord;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Exceptions\InvalidRouteQueryException;
use Beacon\Inspectors\RouteInspector;
use Beacon\Inspectors\Routes\Checks\AuthenticationMiddlewareExcluded;
use Beacon\Inspectors\Routes\Checks\DuplicateRouteNames;
use Beacon\Inspectors\Routes\Checks\SensitiveRoutePaths;
use Beacon\Inspectors\Routes\RouteQuery;
use Beacon\Security\Principal;
use Beacon\Tests\Fixtures\RouteSpyController;
use Beacon\Tests\TestCase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class RouteInspectorTest extends TestCase
{
    #[Test]
    public function an_empty_route_table_passes_every_check(): void
    {
        $inspection = $this->inspect();

        $this->assertSame(1, $inspection->schemaVersion);
        $this->assertSame(0, $inspection->pagination->total);
        $this->assertSame(50, $inspection->pagination->perPage);
        $this->assertSame(1, $inspection->pagination->page);
        $this->assertSame([], $inspection->routes);

        foreach ($inspection->findings as $finding) {
            $this->assertSame('pass', $finding->status->value);
        }
    }

    #[Test]
    public function it_collects_named_unnamed_and_multi_method_routes_without_running_them(): void
    {
        $calls = 0;
        Route::get('/closed', function () use (&$calls): string {
            $calls++;

            return 'closure-body-not-in-output';
        });
        Route::match(['get', 'post'], '/item', fn (): string => 'matched-body-not-in-output')->name('item');
        Route::get('/users/{user}', [RouteSpyController::class, 'show'])
            ->middleware(['auth', 'throttle:60,1'])
            ->name('users.show');
        Route::domain('shop.example.com')->group(function (): void {
            Route::get('/cart', fn (): string => 'cart')->name('cart');
        });

        $inspection = $this->inspect();
        $json = (string) json_encode($inspection->toArray());

        $this->assertSame(0, $calls);
        $this->assertSame(0, RouteSpyController::$constructed);
        $this->assertSame(0, RouteSpyController::$invoked);
        $this->assertStringNotContainsString('closure-body-not-in-output', $json);
        $this->assertStringNotContainsString('handler-body-not-in-output', $json);
        $this->assertStringNotContainsString('RouteInspectorTest.php', $json);
        $this->assertStringNotContainsString($this->application()->basePath(), $json);

        $closed = $this->route($inspection, 'closed');
        $this->assertNull($closed->name);
        $this->assertSame('closure', $closed->action);
        $this->assertSame(['GET', 'HEAD'], $closed->methods);
        $this->assertNull($closed->frameworkGenerated);

        $item = $this->route($inspection, 'item');
        $this->assertSame(['GET', 'HEAD', 'POST'], $item->methods);
        $this->assertSame('item', $item->name);

        $users = $this->route($inspection, 'users/{user}');
        $this->assertSame('users.show', $users->name);
        $this->assertSame(['auth', 'throttle:60,1'], $users->middleware);
        $this->assertSame(RouteSpyController::class . '@show', $users->action);

        $cart = $this->route($inspection, 'cart');
        $this->assertSame('shop.example.com', $cart->domain);
    }

    #[Test]
    public function filters_pagination_and_ordering_are_literal_and_stable(): void
    {
        Route::get('/z', fn (): string => 'z')->name('zeta');
        Route::get('/a', fn (): string => 'a')->name('alpha.show');
        Route::post('/a', fn (): string => 'posted')->name('alpha.store');
        Route::get('/m', fn (): string => 'm')->middleware('auth.basic')->name('middle');

        $page = $this->inspect(['limit' => 2, 'page' => 1]);

        $this->assertSame(4, $page->pagination->total);
        $this->assertSame(2, $page->pagination->pages);
        $this->assertSame(2, $page->pagination->count);
        $this->assertSame(['a', 'a'], array_map(static fn (RouteRecord $route): ?string => $route->uri, $page->routes));
        $this->assertSame(['alpha.show', 'alpha.store'], array_map(static fn (RouteRecord $route): ?string => $route->name, $page->routes));

        $second = $this->inspect(['limit' => 2, 'page' => 2]);
        $this->assertSame(['m', 'z'], array_map(static fn (RouteRecord $route): ?string => $route->uri, $second->routes));

        $this->assertSame(['a'], array_map(
            static fn (RouteRecord $route): ?string => $route->uri,
            $this->inspect(['method' => 'post'])->routes,
        ));
        $this->assertSame(['alpha.show'], array_map(
            static fn (RouteRecord $route): ?string => $route->name,
            $this->inspect(['uri' => '/a', 'uri_match' => 'exact', 'method' => 'GET'])->routes,
        ));
        $this->assertSame(['alpha.show', 'alpha.store'], array_map(
            static fn (RouteRecord $route): ?string => $route->name,
            $this->inspect(['name' => 'alpha', 'name_match' => 'contains'])->routes,
        ));
        $this->assertSame([], $this->inspect(['name' => 'alpha', 'name_match' => 'exact'])->routes);
        $this->assertSame(['middle'], array_map(
            static fn (RouteRecord $route): ?string => $route->name,
            $this->inspect(['middleware' => 'auth.basic'])->routes,
        ));
        $this->assertSame([], $this->inspect(['middleware' => 'auth'])->routes);
        $this->assertSame([], $this->inspect(['uri' => 'missing'])->routes);
        $this->assertSame([], $this->inspect(['page' => 9, 'limit' => 2])->routes);
        $this->assertSame(4, $this->inspect(['page' => 9, 'limit' => 2])->pagination->total);
    }

    #[Test]
    public function findings_cover_the_whole_table_and_do_not_claim_a_route_is_vulnerable(): void
    {
        Route::get('/shared-a', fn (): string => 'a')->name('shared');
        Route::get('/shared-b', fn (): string => 'b')->name('shared');
        Route::get('/telescope/requests', fn (): string => 'telescope')->name('telescope');
        Route::get('/storage-report', fn (): string => 'report')->name('reports');
        Route::get('/account', fn (): string => 'account')->name('account')->withoutMiddleware('auth');

        $inspection = $this->inspect(['limit' => 1]);
        $json = (string) json_encode($inspection->toArray());

        $this->assertSame(1, $inspection->pagination->count);
        $this->assertSame('risk', $this->finding($inspection, DuplicateRouteNames::ID)->status->value);
        $this->assertStringContainsString('shared', $this->finding($inspection, DuplicateRouteNames::ID)->description);
        $this->assertStringContainsString('not a vulnerability', $this->finding($inspection, DuplicateRouteNames::ID)->description);

        $paths = $this->finding($inspection, SensitiveRoutePaths::ID);
        $this->assertSame('risk', $paths->status->value);
        $this->assertSame('heuristic', $paths->evidence[0]->observation->value);
        $this->assertStringContainsString('telescope/requests', $paths->description);
        $this->assertStringNotContainsString('storage-report', $paths->description);
        $this->assertStringContainsString('does not mean the route is vulnerable', $paths->description);

        $auth = $this->finding($inspection, AuthenticationMiddlewareExcluded::ID);
        $this->assertSame('risk', $auth->status->value);
        $this->assertStringContainsString('account', $auth->description);
        $this->assertStringContainsString('does not prove', $auth->description);
        $this->assertStringNotContainsString('secret', $json);
    }

    #[Test]
    public function an_unsafe_domain_and_an_unreadable_exclusion_are_withheld(): void
    {
        $route = Route::get('/secret', fn (): string => 'secret-body-not-in-output');
        $action = $route->getAction();
        $this->assertIsArray($action);
        $action['domain'] = 'user:secret-not-in-output@shop.example.com';
        $action['excluded_middleware'] = [static fn (): string => 'secret-not-in-output'];
        $route->setAction($action);

        $inspection = $this->inspect();
        $json = (string) json_encode($inspection->toArray());
        $record = $inspection->routes[0];

        $this->assertNull($record->domain);
        $this->assertSame('unknown', $this->finding($inspection, AuthenticationMiddlewareExcluded::ID)->status->value);
        $this->assertStringNotContainsString('secret-not-in-output', $json);
        $this->assertStringNotContainsString('RouteInspectorTest.php', $json);
    }

    #[Test]
    public function expression_like_filters_are_rejected_as_literal_text(): void
    {
        foreach (['(.*)', '.*', 'users id', 'users/^id'] as $uri) {
            try {
                $this->inspect(['uri' => $uri]);
                $this->fail('Accepted URI filter [' . $uri . '].');
            } catch (InvalidRouteQueryException $exception) {
                $this->assertSame('The uri filter contains characters that are not allowed.', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function a_non_scalar_filter_is_rejected(): void
    {
        $this->expectException(InvalidRouteQueryException::class);
        $this->expectExceptionMessage('The uri filter has an unsupported type.');

        RouteQuery::from(['uri' => ['users']]);
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    #[Test]
    #[DataProvider('invalidFilters')]
    public function invalid_filters_are_rejected(array $parameters, string $message): void
    {
        $this->expectException(InvalidRouteQueryException::class);
        $this->expectExceptionMessage($message);

        $this->inspect($parameters);
    }

    /**
     * @return array<string, array{0: array<string, scalar|null>, 1: string}>
     */
    public static function invalidFilters(): array
    {
        return [
            'match mode' => [['name_match' => 'regex'], 'The name_match filter must be contains or exact.'],
            'trace' => [['method' => 'TRACE'], 'The method filter must be one HTTP verb: GET, HEAD, POST, PUT, PATCH, DELETE or OPTIONS.'],
            'method type' => [['method' => 1], 'The method filter has an unsupported type.'],
            'whitespace' => [['uri' => '   '], 'The uri filter must not be empty.'],
            'unknown' => [['path' => '/etc/passwd'], 'Unknown route filter. Allowed filters are method, uri, uri_match, name, name_match, middleware, page and limit.'],
            'page' => [['page' => 0], 'The page filter must be an integer from 1 to 10000.'],
            'page type' => [['page' => '2'], 'The page filter has an unsupported type.'],
            'limit' => [['limit' => 101], 'The limit filter must be an integer from 1 to 100.'],
            'middleware path' => [['middleware' => '/etc/passwd'], 'The middleware filter contains characters that are not allowed.'],
            'empty' => [['name' => '   '], 'The name filter must not be empty.'],
        ];
    }

    #[Test]
    public function it_is_registered_under_the_routes_capability(): void
    {
        $registry = $this->application()->make(InspectorRegistry::class);

        $this->assertTrue($registry->enabled(RouteInspector::NAME));
        $this->assertInstanceOf(RouteInspector::class, $registry->get(RouteInspector::NAME));
        $this->assertSame('routes:read', (string) $this->inspector()->capability());
    }

    #[Test]
    public function it_runs_through_beacon_only_when_the_routes_capability_is_allowed(): void
    {
        Route::get('/users', fn (): string => 'users')->name('users');
        $beacon = $this->application()->make(Beacon::class);

        try {
            $beacon->inspect(Principal::local(), RouteInspector::NAME);
            $this->fail('The routes capability was allowed by default.');
        } catch (AuthorizationException) {
        }

        config()->set('beacon.security.allowed_capabilities', ['routes']);

        $this->assertSame($this->inspect()->toArray(), $beacon->inspect(Principal::local(), RouteInspector::NAME)->toArray());
    }

    #[Test]
    public function it_can_be_disabled(): void
    {
        config()->set('beacon.inspectors.routes.enabled', false);
        config()->set('beacon.security.allowed_capabilities', ['routes']);

        $this->expectException(InspectorNotFoundException::class);

        $this->application()->make(Beacon::class)->inspect(Principal::local(), RouteInspector::NAME);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->application()->make('router')->setRoutes(new RouteCollection());
        RouteSpyController::$constructed = 0;
        RouteSpyController::$invoked = 0;
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function inspect(array $parameters = []): RouteInspection
    {
        $inspection = $this->inspector()->inspect($parameters);
        $this->assertInstanceOf(RouteInspection::class, $inspection);

        return $inspection;
    }

    private function inspector(): RouteInspector
    {
        return $this->application()->make(RouteInspector::class);
    }

    private function route(RouteInspection $inspection, string $uri): RouteRecord
    {
        foreach ($inspection->routes as $route) {
            if ($route->uri === $uri) {
                return $route;
            }
        }

        $this->fail('Missing route [' . $uri . '].');
    }

    private function finding(RouteInspection $inspection, string $id): Finding
    {
        foreach ($inspection->findings as $finding) {
            if ($finding->id === $id) {
                return $finding;
            }
        }

        $this->fail('Missing finding [' . $id . '].');
    }
}
