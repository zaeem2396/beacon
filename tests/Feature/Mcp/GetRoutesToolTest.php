<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Mcp;

use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Data\RouteInspection;
use Beacon\Events\AuthorizationEvaluated;
use Beacon\Inspectors\RouteInspector;
use Beacon\Mcp\ExecutionGate;
use Beacon\Mcp\Tools\GetRoutesTool;
use Beacon\Security\Principal;
use Beacon\Tests\Fixtures\FailingRouteInspector;
use Beacon\Tests\Fixtures\RouteSpyController;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response as McpResponse;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The route tool through the real, authenticated MCP HTTP lifecycle.
 */
#[Group('mcp')]
final class GetRoutesToolTest extends McpTestCase
{
    private const URL = 'https://localhost/beacon/mcp';

    private static int $invocations = 0;

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function it_is_listed_as_a_read_only_tool_with_documented_filters_and_output(): void
    {
        $tools = (array) $this->rpc('tools/list')->assertOk()->json('result.tools');
        $tool = collect($tools)->firstWhere('name', 'beacon.get_routes');

        $this->assertIsArray($tool);
        $this->assertSame('beacon.get_routes', $tool['name']);
        $this->assertSame('Get route inspection', $tool['title']);

        $annotations = $tool['annotations'];
        $this->assertIsArray($annotations);
        $this->assertSame(
            ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            array_diff_key($annotations, ['title' => true]),
        );

        if (array_key_exists('title', $annotations)) {
            $this->assertSame('Get route inspection', $annotations['title']);
        }

        $properties = (array) data_get($tool, 'inputSchema.properties');
        $this->assertSame(
            ['method', 'uri', 'uri_match', 'name', 'name_match', 'middleware', 'page', 'limit'],
            array_keys($properties),
        );
        $limitDescription = data_get($properties, 'limit.description');
        $this->assertIsString($limitDescription);
        $this->assertStringContainsString('Defaults to 50', $limitDescription);
        $this->assertSame(1, data_get($properties, 'page.minimum'));
        $this->assertSame(10000, data_get($properties, 'page.maximum'));
        $this->assertSame(1, data_get($properties, 'limit.minimum'));
        $this->assertSame(100, data_get($properties, 'limit.maximum'));
        $this->assertSame(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], data_get($properties, 'method.enum'));
        $this->assertSame(['contains', 'exact'], data_get($properties, 'uri_match.enum'));

        $this->assertSame(
            ['schema_version', 'pagination', 'routes', 'findings'],
            array_keys((array) data_get($tool, 'outputSchema.properties')),
        );
        $this->assertSame(['schema_version', 'pagination', 'routes', 'findings'], data_get($tool, 'outputSchema.required'));
        $this->assertSame(
            ['methods', 'uri', 'name', 'domain', 'middleware', 'action', 'framework_generated'],
            array_keys((array) data_get($tool, 'outputSchema.properties.routes.items.properties')),
        );

        $description = $tool['description'];
        $this->assertIsString($description);
        $description = preg_replace('/\s+/', ' ', $description);
        $this->assertIsString($description);

        foreach ([
            'duplicate_route_names',
            'sensitive_route_paths',
            'authentication_middleware_excluded',
            'framework_generated',
            'contains',
            'exact',
            '100',
            'does not mean the route is vulnerable',
            'Does not call route handlers',
            '"routes" capability',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $description);
        }
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function an_authorized_client_receives_route_metadata_without_running_handlers(): void
    {
        Route::get('/ran', function (): string {
            self::$invocations++;

            return 'handler-body-not-in-output';
        });
        Route::get('/users/{user}', [RouteSpyController::class, 'show'])
            ->name('users.show')
            ->middleware(['auth', 'throttle:60,1']);

        $response = $this->callTool()->assertOk();
        $response->assertJsonPath('result.isError', false);

        $structured = (array) $response->json('result.structuredContent');

        $this->assertSame($this->expected(), $structured);
        $this->assertSame(0, self::$invocations);
        $this->assertSame(0, RouteSpyController::$constructed);
        $this->assertSame(0, RouteSpyController::$invoked);

        $closure = $this->route($structured, 'ran');
        $named = $this->route($structured, 'users/{user}');

        $this->assertSame('closure', $closure['action']);
        $this->assertNull($closure['framework_generated']);
        $this->assertSame(['auth', 'throttle:60,1'], $named['middleware']);
        $this->assertSame(RouteSpyController::class . '@show', $named['action']);

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('handler-body-not-in-output', $body);
        $this->assertStringNotContainsString('RouteInspectorTest.php', $body);
        $this->assertStringNotContainsString('GetRoutesToolTest.php', $body);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function filters_and_pagination_apply_to_records_while_findings_cover_the_table(): void
    {
        Route::get('/z', fn (): string => 'z')->name('shared');
        Route::post('/a', fn (): string => 'a')->name('shared');

        $response = $this->callTool([
            'method' => 'post',
            'uri' => 'a',
            'uri_match' => 'exact',
            'limit' => 1,
        ])->assertOk();
        $structured = (array) $response->json('result.structuredContent');

        $this->assertSame('a', data_get($structured, 'routes.0.uri'));
        $this->assertSame(['POST'], data_get($structured, 'routes.0.methods'));
        $this->assertSame(1, data_get($structured, 'pagination.count'));
        $this->assertSame(1, data_get($structured, 'pagination.total'));
        $this->assertSame('risk', data_get($structured, 'findings.0.status'));
        $duplicate = data_get($structured, 'findings.0.description');
        $this->assertIsString($duplicate);
        $this->assertStringContainsString('shared', $duplicate);
        $this->assertStringContainsString('not a vulnerability', $duplicate);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function invalid_filters_return_a_fixed_message_without_the_rejected_value(): void
    {
        $logged = $this->captureLog();

        $response = $this->callTool(['method' => 'TRACE', 'path' => '/etc/passwd', 'limit' => 101])->assertOk();

        $response->assertJsonPath('result.isError', true);
        $response->assertJsonPath(
            'result.content.0.text',
            'Unknown route filter. Allowed filters are method, uri, uri_match, name, name_match, middleware, page and limit.',
        );
        $response->assertJsonMissingPath('result.structuredContent');

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('TRACE', $body);
        $this->assertStringNotContainsString('/etc/passwd', $body);
        $this->assertStringNotContainsString('101', $body);
        $this->assertStringNotContainsString('InvalidRouteQueryException', $logged());
        $this->assertStringNotContainsString('GetRoutesTool.php', $logged());
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function a_non_scalar_filter_is_rejected_by_name_without_running_routes(): void
    {
        Route::get('/ran', function (): string {
            self::$invocations++;

            return 'handler-body-not-in-output';
        });

        $response = $this->callTool(['uri' => ['users']])->assertOk();

        $response->assertJsonPath('result.isError', true);
        $response->assertJsonPath('result.content.0.text', 'The uri filter has an unsupported type.');
        $this->assertSame(0, self::$invocations);
        $this->assertStringNotContainsString('handler-body-not-in-output', (string) $response->getContent());
    }

    #[Test]
    #[DefineEnvironment('enableHttpWithoutRoutesCapability')]
    public function the_execution_gate_rejects_a_caller_without_the_routes_capability(): void
    {
        Event::fake([AuthorizationEvaluated::class]);

        $response = $this->callTool();

        $response->assertJsonPath('error.code', ExecutionGate::PERMISSION_DENIED);
        $response->assertJsonPath('error.message', ExecutionGate::DENIED_MESSAGE);
        $response->assertJsonMissingPath('result');

        Event::assertDispatched(AuthorizationEvaluated::class, static fn (AuthorizationEvaluated $event): bool => ! $event->allowed
            && $event->capability === 'routes:read');
    }

    #[Test]
    #[DefineEnvironment('enableHttpWithoutRoutesCapability')]
    public function the_tool_enforces_its_capability_even_if_the_execution_gate_is_bypassed(): void
    {
        $response = $this->invokeDirectly(new Principal('beacon-token:current', 'bearer-token'));

        $this->assertTrue($response->isError());
        $this->assertSame(ExecutionGate::DENIED_MESSAGE, (string) $response->content());
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function a_successful_call_is_authorized_twice(): void
    {
        Event::fake([AuthorizationEvaluated::class]);

        $this->callTool()->assertOk()->assertJsonPath('result.isError', false);

        Event::assertDispatchedTimes(AuthorizationEvaluated::class, 2);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function unauthenticated_clients_are_rejected_before_the_tool_runs(): void
    {
        $this->postJson(self::URL, $this->message())->assertStatus(401)->assertJsonMissingPath('result');
        $this->postJson(self::URL, $this->message(), ['Authorization' => 'Bearer ' . str_repeat('b', 32)])
            ->assertStatus(401)
            ->assertJsonMissingPath('result');
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function inspection_failures_return_a_generic_error_without_internal_details(): void
    {
        $logged = $this->captureLog();
        $this->application()->make(InspectorRegistry::class)->register(RouteInspector::NAME, FailingRouteInspector::class);

        foreach ([true, false] as $debug) {
            config()->set('app.debug', $debug);

            $response = $this->callTool()->assertOk();

            $response->assertJsonPath('result.isError', true);
            $response->assertJsonPath('result.content.0.text', GetRoutesTool::UNAVAILABLE_MESSAGE);
            $response->assertJsonMissingPath('result.structuredContent');

            $body = (string) $response->getContent();

            foreach ([...FailingRouteInspector::DETAILS, 'route-inspector-secret', 'db.internal', 'RuntimeException', 'FailingRouteInspector'] as $detail) {
                $this->assertStringNotContainsString($detail, $body);
                $this->assertStringNotContainsString($detail, $logged());
            }
        }
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function a_disabled_inspector_removes_only_this_tool(): void
    {
        config()->set('beacon.inspectors.routes.enabled', false);

        $names = array_column((array) $this->rpc('tools/list')->assertOk()->json('result.tools'), 'name');

        $this->assertNotContains('beacon.get_routes', $names);
        $this->assertContains('beacon.get_application_info', $names);
        $this->assertContains('beacon.get_environment', $names);
        $this->callTool()->assertJsonMissingPath('result.structuredContent');
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::$invocations = 0;
        RouteSpyController::$constructed = 0;
        RouteSpyController::$invoked = 0;

        $router = $this->application()->make('router');
        $existing = $router->getRoutes()->getRoutes();
        $kept = new RouteCollection();

        if (is_array($existing)) {
            foreach ($existing as $route) {
                if ($route instanceof \Illuminate\Routing\Route && $route->uri() === 'beacon/mcp') {
                    $kept->add($route);
                }
            }
        }

        $router->setRoutes($kept);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableHttp($app): void
    {
        parent::enableHttp($app);
        $app['config']->set('beacon.security.allowed_capabilities', ['routes']);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableHttpWithoutRoutesCapability($app): void
    {
        parent::enableHttp($app);
        $app['config']->set('beacon.security.allowed_capabilities', ['application']);
    }

    /**
     * @param array<string, mixed> $structured
     *
     * @return array<string, mixed>
     */
    private function route(array $structured, string $uri): array
    {
        foreach ((array) data_get($structured, 'routes') as $route) {
            if (is_array($route) && ($route['uri'] ?? null) === $uri) {
                return $route;
            }
        }

        $this->fail('Missing route [' . $uri . '].');
    }

    /**
     * @return \Closure(): string
     */
    private function captureLog(): \Closure
    {
        $logged = '';

        Log::listen(function (MessageLogged $message) use (&$logged): void {
            $logged .= $message->message . ' ' . json_encode($message->context);
        });

        return static function () use (&$logged): string {
            return $logged;
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function expected(): array
    {
        $inspection = $this->application()->make(RouteInspector::class)->inspect();
        $this->assertInstanceOf(RouteInspection::class, $inspection);

        return $inspection->toArray();
    }

    private function invokeDirectly(Principal $principal): McpResponse
    {
        $response = (new GetRoutesTool())->handle(
            $this->application()->make(Beacon::class),
            $principal,
            new Request([]),
        );
        $this->assertInstanceOf(McpResponse::class, $response);

        return $response;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return TestResponse<Response>
     */
    private function callTool(array $arguments = []): TestResponse
    {
        return $this->postJson(self::URL, $this->message('tools/call', [
            'name' => 'beacon.get_routes',
            'arguments' => $arguments,
        ]), ['Authorization' => 'Bearer ' . self::TOKEN]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return TestResponse<Response>
     */
    private function rpc(string $method, array $params = []): TestResponse
    {
        return $this->postJson(self::URL, $this->message($method, $params), ['Authorization' => 'Bearer ' . self::TOKEN]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function message(string $method = 'tools/call', array $params = ['name' => 'beacon.get_routes', 'arguments' => []]): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => (object) $params,
        ];
    }
}
