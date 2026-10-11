<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Mcp;

use Beacon\Mcp\Http\AuthenticateMcpRequest;
use Beacon\Mcp\McpRegistry;
use Beacon\Mcp\ServerRegistration;
use Beacon\Tests\Fixtures\Mcp\EchoTool;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;
use Laravel\Mcp\Server\Middleware\ReorderJsonAccept;
use Laravel\Mcp\Server\Middleware\ValidateMcpHeaders;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('mcp')]
final class HttpAuthenticationTest extends McpTestCase
{
    private const URL = 'https://localhost/beacon/mcp';

    private const OTHER_TOKEN = 'fedcba9876543210fedcba9876543210';

    #[Test]
    public function the_http_endpoint_does_not_exist_by_default(): void
    {
        $this->assertFalse($this->application()->make(ServerRegistration::class)->httpRegistered());
        $this->assertNull($this->mcpRoute());

        $this->postJson(self::URL, $this->initialize(), $this->bearer())->assertNotFound();
    }

    #[Test]
    #[DefineEnvironment('enableMcp')]
    public function enabling_mcp_does_not_enable_the_http_transport(): void
    {
        $registration = $this->application()->make(ServerRegistration::class);

        $this->assertTrue($registration->registered());
        $this->assertFalse($registration->httpRegistered());
        $this->assertStringContainsString('BEACON_MCP_HTTP_ENABLED', $registration->decideHttp()->reason);
        $this->postJson(self::URL, $this->initialize(), $this->bearer())->assertNotFound();
    }

    #[Test]
    #[DefineEnvironment('enableHttpWithoutToken')]
    public function the_http_endpoint_is_not_registered_without_a_token(): void
    {
        $this->assertFalse($this->application()->make(ServerRegistration::class)->httpRegistered());
        $this->assertNull($this->mcpRoute());

        $this->postJson(self::URL, $this->initialize())->assertNotFound();
    }

    #[Test]
    #[DefineEnvironment('enableHttpWithShortToken')]
    public function the_http_endpoint_is_not_registered_with_a_short_token(): void
    {
        $this->assertFalse($this->application()->make(ServerRegistration::class)->httpRegistered());

        $this->postJson(self::URL, $this->initialize(), ['Authorization' => 'Bearer too-short'])->assertNotFound();
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function requests_without_credentials_are_rejected(): void
    {
        $response = $this->postJson(self::URL, $this->initialize());

        $response->assertUnauthorized();
        $response->assertExactJson(['message' => 'Unauthenticated.']);
        $this->assertStringStartsWith('Bearer ', (string) $response->headers->get('WWW-Authenticate'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedAuthorizationHeaders(): iterable
    {
        yield 'basic scheme' => ['Basic ' . base64_encode('user:' . self::TOKEN)];
        yield 'scheme without token' => ['Bearer'];
        yield 'token without scheme' => [self::TOKEN];
        yield 'token with spaces' => ['Bearer ' . substr(self::TOKEN, 0, 16) . ' ' . substr(self::TOKEN, 16)];
        yield 'invalid characters' => ['Bearer ' . self::TOKEN . '"'];
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    #[DataProvider('malformedAuthorizationHeaders')]
    public function malformed_credentials_are_rejected(string $header): void
    {
        $response = $this->postJson(self::URL, $this->initialize(), ['Authorization' => $header]);

        $response->assertStatus(400);
        $response->assertExactJson(['message' => 'Bad request.']);
        $this->assertSame(0, EchoTool::$calls);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function tokens_in_the_query_string_are_rejected_even_with_a_valid_header(): void
    {
        $this->postJson(self::URL . '?access_token=' . self::TOKEN, $this->initialize(), $this->bearer())
            ->assertStatus(400);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTokens(): iterable
    {
        yield 'different token' => [self::OTHER_TOKEN];
        yield 'prefix of the token' => [substr(self::TOKEN, 0, 31)];
        yield 'token with suffix' => [self::TOKEN . 'a'];
        yield 'different case' => [strtoupper(self::TOKEN)];
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    #[DataProvider('invalidTokens')]
    public function invalid_tokens_are_rejected(string $token): void
    {
        $response = $this->postJson(self::URL, $this->initialize(), $this->bearer($token));

        $response->assertUnauthorized();
        $response->assertExactJson(['message' => 'Unauthenticated.']);
        $this->assertStringContainsString('invalid_token', (string) $response->headers->get('WWW-Authenticate'));
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function a_valid_token_completes_the_initialize_handshake(): void
    {
        $response = $this->postJson(self::URL, $this->initialize(), $this->bearer());

        $response->assertOk();
        $response->assertJsonPath('jsonrpc', '2.0');
        $response->assertJsonPath('id', 1);
        $response->assertJsonPath('result.protocolVersion', '2025-06-18');
        $response->assertJsonPath('result.serverInfo.name', 'Laravel Beacon');
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function the_bearer_scheme_is_case_insensitive(): void
    {
        $this->postJson(self::URL, $this->initialize(), ['Authorization' => 'bearer ' . self::TOKEN])->assertOk();
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function every_subsequent_request_is_authenticated(): void
    {
        $this->postJson(self::URL, $this->initialize(), $this->bearer())->assertOk();

        $this->postJson(self::URL, $this->rpc('ping', id: 2))->assertUnauthorized();
        $this->postJson(self::URL, $this->rpc('tools/list', id: 3))->assertUnauthorized();
        $this->postJson(self::URL, $this->rpc('tools/list', id: 4), $this->bearer(self::OTHER_TOKEN))->assertUnauthorized();

        $this->postJson(self::URL, $this->rpc('ping', id: 5), $this->bearer())
            ->assertOk()
            ->assertJsonPath('id', 5);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function unauthenticated_requests_cannot_invoke_tools(): void
    {
        $this->application()->make(McpRegistry::class)->tool(EchoTool::class);
        $call = $this->rpc('tools/call', ['name' => 'echo-tool', 'arguments' => (object) []]);

        $this->postJson(self::URL, $call)->assertUnauthorized();
        $this->postJson(self::URL, $call, $this->bearer(self::OTHER_TOKEN))->assertUnauthorized();
        $this->postJson(self::URL, $call, ['Authorization' => 'Basic ' . base64_encode(self::TOKEN)])->assertStatus(400);
        $this->assertSame(0, EchoTool::$calls);

        $this->postJson(self::URL, $call, $this->bearer())
            ->assertOk()
            ->assertJsonPath('result.content.0.text', 'echo');
        $this->assertSame(1, EchoTool::$calls);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function get_and_delete_require_authentication_before_revealing_the_endpoint(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->deleteJson(self::URL)->assertUnauthorized();

        $this->getJson(self::URL, $this->bearer())->assertStatus(405);
        $this->deleteJson(self::URL, [], $this->bearer())->assertStatus(405);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function plain_http_is_refused_by_default(): void
    {
        $this->postJson('http://localhost/beacon/mcp', $this->initialize(), $this->bearer())
            ->assertForbidden()
            ->assertExactJson(['message' => 'HTTPS is required.']);
    }

    #[Test]
    #[DefineEnvironment('enableHttpOverPlainHttp')]
    public function plain_http_can_be_allowed_but_still_requires_a_token(): void
    {
        $this->postJson('http://localhost/beacon/mcp', $this->initialize())->assertUnauthorized();
        $this->postJson('http://localhost/beacon/mcp', $this->initialize(), $this->bearer())->assertOk();
    }

    #[Test]
    #[DefineEnvironment('enableHttpOnCustomPath')]
    public function the_endpoint_path_is_configurable(): void
    {
        $this->postJson(self::URL, $this->initialize(), $this->bearer())->assertNotFound();
        $this->postJson('https://localhost/internal/ai/beacon', $this->initialize())->assertUnauthorized();
        $this->postJson('https://localhost/internal/ai/beacon', $this->initialize(), $this->bearer())->assertOk();
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function the_previous_token_is_accepted_during_rotation_only(): void
    {
        $config = $this->application()->make('config');
        $config->set('beacon.auth.token', self::OTHER_TOKEN);
        $config->set('beacon.auth.previous_token', self::TOKEN);

        $this->postJson(self::URL, $this->initialize(), $this->bearer(self::OTHER_TOKEN))->assertOk();
        $this->postJson(self::URL, $this->initialize(), $this->bearer())->assertOk();

        $config->set('beacon.auth.previous_token', null);

        $this->postJson(self::URL, $this->initialize(), $this->bearer())->assertUnauthorized();
        $this->postJson(self::URL, $this->initialize(), $this->bearer(self::OTHER_TOKEN))->assertOk();
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function a_registered_route_fails_closed_when_access_is_withdrawn(): void
    {
        $config = $this->application()->make('config');

        $config->set('beacon.auth.token', null);
        $this->postJson(self::URL, $this->initialize(), $this->bearer())->assertNotFound();

        $config->set('beacon.auth.token', self::TOKEN);
        $config->set('beacon.mcp.http.enabled', false);
        $this->postJson(self::URL, $this->initialize(), $this->bearer())->assertNotFound();

        $config->set('beacon.mcp.http.enabled', true);
        $config->set('beacon.mcp.allowed_environments', ['local']);
        $this->postJson(self::URL, $this->initialize(), $this->bearer())->assertNotFound();
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function credentials_never_appear_in_responses_or_logs(): void
    {
        $this->application()->make(McpRegistry::class)->tool(EchoTool::class);

        /** @var list<string> $logged */
        $logged = [];
        Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message . json_encode($event->context);
        });
        Log::info('log capture is active');

        $responses = [
            $this->postJson(self::URL, $this->initialize()),
            $this->postJson(self::URL, $this->initialize(), $this->bearer(self::OTHER_TOKEN)),
            $this->postJson(self::URL, $this->initialize(), ['Authorization' => 'Basic ' . self::OTHER_TOKEN]),
            $this->postJson(self::URL . '?access_token=' . self::OTHER_TOKEN, $this->initialize()),
            $this->postJson(self::URL, $this->initialize(), $this->bearer()),
            $this->postJson(self::URL, $this->rpc('tools/call', ['name' => 'echo-tool', 'arguments' => (object) []]), $this->bearer()),
            $this->postJson(self::URL, $this->rpc('tools/call', ['name' => 'missing', 'arguments' => (object) []]), $this->bearer()),
        ];

        $this->assertNotEmpty($logged);

        foreach ($responses as $response) {
            $this->assertNoCredentials($response->headers->__toString() . $response->getContent());
        }

        $this->assertNoCredentials(implode("\n", $logged));
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function authentication_runs_before_the_laravel_mcp_middleware(): void
    {
        $route = $this->mcpRoute();
        $this->assertInstanceOf(Route::class, $route);

        $this->assertSame([
            AuthenticateMcpRequest::class,
            ReorderJsonAccept::class,
            ValidateMcpHeaders::class,
            AddWwwAuthenticateHeader::class,
        ], $route->gatherMiddleware());
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function unrelated_routes_are_unaffected(): void
    {
        $this->get('https://localhost/unrelated')->assertOk()->assertSeeText('unrelated-get');
        $this->post('https://localhost/unrelated')->assertOk()->assertSeeText('unrelated-post');
        $this->get('http://localhost/unrelated')->assertOk();

        $route = $this->application()->make(Router::class)->getRoutes()->match(
            \Illuminate\Http\Request::create('https://localhost/unrelated', 'POST'),
        );

        $this->assertNotContains(AuthenticateMcpRequest::class, $route->gatherMiddleware());
    }

    protected function setUp(): void
    {
        parent::setUp();

        EchoTool::$calls = 0;
    }

    /**
     * @param Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('unrelated', static fn (): string => 'unrelated-get');
        $router->post('unrelated', static fn (): string => 'unrelated-post');
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableHttpWithoutToken($app): void
    {
        $this->enableHttp($app);
        $app['config']->set('beacon.auth.token', null);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableHttpWithShortToken($app): void
    {
        $this->enableHttp($app);
        $app['config']->set('beacon.auth.token', 'too-short');
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableHttpOverPlainHttp($app): void
    {
        $this->enableHttp($app);
        $app['config']->set('beacon.mcp.http.require_https', false);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableHttpOnCustomPath($app): void
    {
        $this->enableHttp($app);
        $app['config']->set('beacon.mcp.http.path', '/internal/ai/beacon/');
    }

    private function assertNoCredentials(string $haystack): void
    {
        $this->assertStringNotContainsString(self::TOKEN, $haystack);
        $this->assertStringNotContainsString(self::OTHER_TOKEN, $haystack);
    }

    private function mcpRoute(): ?Route
    {
        foreach ($this->application()->make(Router::class)->getRoutes()->getRoutes() as $route) {
            if ($route->uri() === 'beacon/mcp' && in_array('POST', $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function bearer(string $token = self::TOKEN): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    /**
     * @return array<string, mixed>
     */
    private function initialize(): array
    {
        return $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'beacon-tests', 'version' => '1.0.0'],
        ]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params = [], int $id = 1): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params];
    }
}
