<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Mcp;

use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Data\ApplicationInfo;
use Beacon\Events\AuthorizationEvaluated;
use Beacon\Inspectors\ApplicationInspector;
use Beacon\Mcp\ExecutionGate;
use Beacon\Mcp\Tools\GetApplicationInfoTool;
use Beacon\Security\Principal;
use Beacon\Tests\Fixtures\FailingApplicationInspector;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Response as McpResponse;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The application tool through the real, authenticated MCP HTTP lifecycle.
 */
#[Group('mcp')]
final class GetApplicationInfoToolTest extends McpTestCase
{
    private const URL = 'https://localhost/beacon/mcp';

    private const FIELDS = [
        'schema_version',
        'name',
        'environment',
        'debug',
        'url',
        'timezone',
        'locale',
        'fallback_locale',
        'maintenance_mode',
        'laravel_version',
        'php_version',
        'beacon_version',
    ];

    /**
     * Configuration that must never appear in a response.
     */
    private const SECRETS = [
        'app.key' => 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=',
        'database.connections.mysql.password' => 'db-password-never-returned',
        'services.stripe.secret' => 'sk_live_51NeverReturnedStripeSecret',
        'mail.mailers.smtp.password' => 'smtp-password-never-returned',
        'session.cookie' => 'session-cookie-never-returned',
    ];

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function it_is_listed_as_a_read_only_tool_with_a_documented_output_schema(): void
    {
        $tools = (array) $this->rpc('tools/list')->assertOk()->json('result.tools');
        $tool = collect($tools)->firstWhere('name', GetApplicationInfoTool::NAME);

        $this->assertIsArray($tool);
        $this->assertSame('beacon.get_application_info', $tool['name']);
        $this->assertSame('Get application information', $tool['title']);

        $annotations = $tool['annotations'];
        $this->assertIsArray($annotations);
        $this->assertSame(
            ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            array_diff_key($annotations, ['title' => true]),
        );

        // laravel/mcp 1.1+ also copies the tool title into annotations.
        if (array_key_exists('title', $annotations)) {
            $this->assertSame('Get application information', $annotations['title']);
        }
        $this->assertSame([], (array) data_get($tool, 'inputSchema.properties'));
        $this->assertSame(self::FIELDS, array_keys((array) data_get($tool, 'outputSchema.properties')));
        $this->assertSame(self::FIELDS, data_get($tool, 'outputSchema.required'));

        $description = $tool['description'];
        $this->assertIsString($description);

        foreach (['Laravel', 'PHP', 'environment', 'debug', 'maintenance', 'null', 'Does not return', 'credentials', '"application" capability'] as $phrase) {
            $this->assertStringContainsString($phrase, $description);
        }
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function an_authorized_client_receives_the_normalized_application_information(): void
    {
        config()->set('app.name', 'Checkout');
        config()->set('app.url', 'https://deploy:hunter2@shop.example.com/?signature=abc#frag');

        $response = $this->callTool()->assertOk();

        $response->assertJsonPath('result.isError', false);

        $structured = (array) $response->json('result.structuredContent');

        $this->assertSame(self::FIELDS, array_keys($structured));
        $this->assertSame($this->expected(), $structured);
        $text = $response->json('result.content.0.text');
        $this->assertIsString($text);
        $this->assertSame($structured, json_decode($text, true));
        $this->assertSame('Checkout', $structured['name']);
        $this->assertSame('testing', $structured['environment']);
        $this->assertSame('https://shop.example.com', $structured['url']);
        $this->assertSame(PHP_VERSION, $structured['php_version']);
        $this->assertSame($this->application()->version(), $structured['laravel_version']);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function debug_mode_is_reported_accurately(): void
    {
        foreach ([true, false] as $debug) {
            config()->set('app.debug', $debug);

            $this->callTool()->assertOk()->assertJsonPath('result.structuredContent.debug', $debug);
        }

        config()->set('app.debug', 'false');

        $this->callTool()->assertOk()->assertJsonPath('result.structuredContent.debug', null);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function missing_or_invalid_optional_configuration_is_reported_as_null(): void
    {
        config()->set('app.name', ['not', 'a', 'string']);
        config()->set('app.url', 'not a url');
        config()->set('app.locale', null);
        config()->set('app.fallback_locale', '../../etc');

        $structured = (array) $this->callTool()->assertOk()->assertJsonPath('result.isError', false)->json('result.structuredContent');

        foreach (['name', 'url', 'locale', 'fallback_locale'] as $field) {
            $this->assertArrayHasKey($field, $structured);
            $this->assertNull($structured[$field], $field);
        }
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function the_response_never_contains_secrets_or_unrelated_configuration(): void
    {
        foreach (self::SECRETS as $key => $value) {
            config()->set($key, $value);
        }

        config()->set('app.url', 'https://deploy:url-password-never-returned@shop.example.com');

        $body = (string) $this->callTool()->assertOk()->getContent();

        foreach ([...array_values(self::SECRETS), 'url-password-never-returned', self::TOKEN] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        $this->assertStringNotContainsString('never-returned', $body);
        $this->assertStringNotContainsString(str_replace('/', '\/', $this->application()->basePath()), $body);
        $this->assertStringNotContainsString($this->application()->basePath(), $body);
    }

    #[Test]
    #[DefineEnvironment('enableHttpWithoutApplicationCapability')]
    public function the_application_capability_is_required(): void
    {
        Event::fake([AuthorizationEvaluated::class]);

        $response = $this->callTool();

        $response->assertJsonPath('error.code', ExecutionGate::PERMISSION_DENIED);
        $response->assertJsonPath('error.message', ExecutionGate::DENIED_MESSAGE);
        $response->assertJsonMissingPath('result');

        Event::assertDispatched(AuthorizationEvaluated::class, static fn (AuthorizationEvaluated $event): bool => ! $event->allowed
            && $event->capability === 'application:read');
    }

    #[Test]
    #[DefineEnvironment('enableHttpWithoutApplicationCapability')]
    public function the_tool_enforces_its_capability_even_if_the_execution_gate_is_bypassed(): void
    {
        $response = $this->invokeDirectly(new Principal('beacon-token:current', 'bearer-token'));

        $this->assertTrue($response->isError());
        $this->assertSame(ExecutionGate::DENIED_MESSAGE, (string) $response->content());
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function unauthenticated_clients_are_rejected_before_the_tool_runs(): void
    {
        $this->postJson(self::URL, $this->message())->assertStatus(401)->assertJsonMissingPath('result');
        $this->postJson(self::URL, $this->message(), ['Authorization' => 'Bearer ' . str_repeat('x', 32)])
            ->assertStatus(401)
            ->assertJsonMissingPath('result');
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function inspection_failures_return_a_generic_error_without_internal_details(): void
    {
        $this->application()->make(InspectorRegistry::class)->register(ApplicationInspector::NAME, FailingApplicationInspector::class);

        foreach ([true, false] as $debug) {
            config()->set('app.debug', $debug);

            $response = $this->callTool()->assertOk();

            $response->assertJsonPath('result.isError', true);
            $response->assertJsonPath('result.content.0.text', GetApplicationInfoTool::UNAVAILABLE_MESSAGE);
            $response->assertJsonMissingPath('result.structuredContent');

            $body = (string) $response->getContent();

            foreach ([...FailingApplicationInspector::DETAILS, 'inspector-secret', 'db.internal', 'RuntimeException', 'FailingApplicationInspector'] as $detail) {
                $this->assertStringNotContainsString($detail, $body);
            }
        }
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function client_arguments_are_ignored(): void
    {
        $expected = $this->callTool()->json('result.structuredContent');

        $this->rpc('tools/call', ['name' => GetApplicationInfoTool::NAME, 'arguments' => ['include' => 'app.key', 'path' => '/etc/passwd']])
            ->assertOk()
            ->assertJsonPath('result.structuredContent', $expected);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function a_disabled_inspector_removes_the_tool(): void
    {
        config()->set('beacon.inspectors.application.enabled', false);

        $names = array_column((array) $this->rpc('tools/list')->assertOk()->json('result.tools'), 'name');

        $this->assertNotContains(GetApplicationInfoTool::NAME, $names);
        $this->callTool()->assertJsonMissingPath('result.structuredContent');
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableHttpWithoutApplicationCapability($app): void
    {
        $this->enableHttp($app);
        $app['config']->set('beacon.security.allowed_capabilities', ['routes']);
    }

    /**
     * @return array<string, mixed>
     */
    private function expected(): array
    {
        $info = $this->application()->make(ApplicationInspector::class)->inspect();
        $this->assertInstanceOf(ApplicationInfo::class, $info);

        return $info->toArray();
    }

    private function invokeDirectly(Principal $principal): McpResponse
    {
        $response = (new GetApplicationInfoTool())->handle($this->application()->make(Beacon::class), $principal);
        $this->assertInstanceOf(McpResponse::class, $response);

        return $response;
    }

    /**
     * @return TestResponse<Response>
     */
    private function callTool(): TestResponse
    {
        return $this->postJson(self::URL, $this->message(), ['Authorization' => 'Bearer ' . self::TOKEN]);
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
    private function message(string $method = 'tools/call', array $params = ['name' => GetApplicationInfoTool::NAME, 'arguments' => []]): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => (object) $params,
        ];
    }
}
