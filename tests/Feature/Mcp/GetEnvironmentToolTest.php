<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Mcp;

use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Data\EnvironmentInspection;
use Beacon\Events\AuthorizationEvaluated;
use Beacon\Inspectors\EnvironmentInspector;
use Beacon\Mcp\ExecutionGate;
use Beacon\Mcp\Tools\GetEnvironmentTool;
use Beacon\Security\Principal;
use Beacon\Tests\Fixtures\FailingEnvironmentInspector;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Response as McpResponse;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The environment tool through the real, authenticated MCP HTTP lifecycle.
 */
#[Group('mcp')]
final class GetEnvironmentToolTest extends McpTestCase
{
    private const URL = 'https://localhost/beacon/mcp';

    private const SECRETS = [
        'app.key' => 'application-key-not-in-output',
        'database.connections.mysql.password' => 'db-password-not-in-output',
        'mail.mailers.smtp.password' => 'smtp-password-not-in-output',
        'services.ses.secret' => 'ses-secret-not-in-output',
    ];

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function it_is_listed_as_a_read_only_tool_with_a_documented_output_schema(): void
    {
        $tools = (array) $this->rpc('tools/list')->assertOk()->json('result.tools');
        $tool = collect($tools)->firstWhere('name', 'beacon.get_environment');

        $this->assertIsArray($tool);
        $this->assertSame('beacon.get_environment', $tool['name']);
        $this->assertSame('Get environment inspection', $tool['title']);

        $annotations = $tool['annotations'];
        $this->assertIsArray($annotations);
        $this->assertSame(
            ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            array_diff_key($annotations, ['title' => true]),
        );

        if (array_key_exists('title', $annotations)) {
            $this->assertSame('Get environment inspection', $annotations['title']);
        }

        $this->assertSame([], (array) data_get($tool, 'inputSchema.properties'));
        $this->assertSame(
            ['schema_version', 'metadata', 'findings'],
            array_keys((array) data_get($tool, 'outputSchema.properties')),
        );
        $this->assertSame(['schema_version', 'metadata', 'findings'], data_get($tool, 'outputSchema.required'));

        $description = $tool['description'];
        $this->assertIsString($description);

        foreach ([
            'debug_enabled_in_production',
            'app_key_configured',
            'app_url_scheme_in_production',
            'session_secure_cookie_in_production',
            'session_http_only_in_production',
            'session_same_site_in_production',
            'pass',
            'fail',
            'risk',
            'unknown',
            'sensitive',
            '.env',
            '"environment" capability',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $description);
        }
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function an_authorized_client_receives_the_normalized_inspection(): void
    {
        config()->set('app.debug', false);
        config()->set('app.url', 'https://deploy:url-password-not-in-output@shop.example.com/store');

        $response = $this->callTool()->assertOk();
        $response->assertJsonPath('result.isError', false);

        $structured = (array) $response->json('result.structuredContent');
        $expected = $this->expected();

        $this->assertSame(['schema_version', 'metadata', 'findings'], array_keys($structured));
        $this->assertSame($expected, $structured);

        $text = $response->json('result.content.0.text');
        $this->assertIsString($text);
        $this->assertSame($structured, json_decode($text, true));
        $this->assertSame('testing', data_get($structured, 'metadata.environment'));
        $this->assertSame('https', data_get($structured, 'metadata.settings.3.value'));
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function debug_enabled_in_production_is_returned_as_a_high_failure(): void
    {
        $this->application()->detectEnvironment(static fn (): string => 'production');
        config()->set('app.debug', true);
        config()->set('session.secure', true);
        config()->set('session.http_only', true);
        config()->set('session.same_site', 'lax');
        config()->set('app.url', 'https://shop.example.com');

        $findings = (array) $this->callTool()->assertOk()->json('result.structuredContent.findings');
        $debug = collect($findings)->firstWhere('id', 'debug_enabled_in_production');

        $this->assertIsArray($debug);
        $this->assertSame('fail', $debug['status']);
        $this->assertSame('high', $debug['severity']);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function secrets_are_absent_from_the_response_and_the_log(): void
    {
        $logged = $this->captureLog();

        foreach (self::SECRETS as $key => $value) {
            config()->set($key, $value);
        }

        config()->set('app.url', 'https://deploy:url-password-not-in-output@shop.example.com');

        $body = (string) $this->callTool()->assertOk()->getContent();

        foreach ([...array_values(self::SECRETS), 'url-password-not-in-output', self::TOKEN, $this->application()->basePath()] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
            $this->assertStringNotContainsString($secret, $logged());
        }
    }

    #[Test]
    #[DefineEnvironment('enableHttpWithoutEnvironmentCapability')]
    public function the_environment_capability_is_required_at_the_execution_boundary(): void
    {
        Event::fake([AuthorizationEvaluated::class]);

        $response = $this->callTool();

        $response->assertJsonPath('error.code', ExecutionGate::PERMISSION_DENIED);
        $response->assertJsonPath('error.message', ExecutionGate::DENIED_MESSAGE);
        $response->assertJsonMissingPath('result');

        Event::assertDispatched(AuthorizationEvaluated::class, static fn (AuthorizationEvaluated $event): bool => ! $event->allowed
            && $event->capability === 'environment:read');
    }

    #[Test]
    #[DefineEnvironment('enableHttpWithoutEnvironmentCapability')]
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
        $this->application()->make(InspectorRegistry::class)->register(EnvironmentInspector::NAME, FailingEnvironmentInspector::class);

        foreach ([true, false] as $debug) {
            config()->set('app.debug', $debug);

            $response = $this->callTool()->assertOk();

            $response->assertJsonPath('result.isError', true);
            $response->assertJsonPath('result.content.0.text', GetEnvironmentTool::UNAVAILABLE_MESSAGE);
            $response->assertJsonMissingPath('result.structuredContent');

            $body = (string) $response->getContent();

            foreach ([...FailingEnvironmentInspector::DETAILS, 'inspector-secret', 'db.internal', 'RuntimeException', 'FailingEnvironmentInspector'] as $detail) {
                $this->assertStringNotContainsString($detail, $body);
                $this->assertStringNotContainsString($detail, $logged());
            }
        }
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function client_arguments_cannot_select_configuration_keys(): void
    {
        $expected = $this->callTool()->json('result.structuredContent');

        $this->rpc('tools/call', ['name' => 'beacon.get_environment', 'arguments' => [
            'keys' => ['app.key', 'database.connections.mysql.password'],
            'path' => '/etc/passwd',
        ]])->assertOk()->assertJsonPath('result.structuredContent', $expected);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function a_disabled_inspector_removes_only_this_tool(): void
    {
        config()->set('beacon.inspectors.environment.enabled', false);

        $names = array_column((array) $this->rpc('tools/list')->assertOk()->json('result.tools'), 'name');

        $this->assertNotContains('beacon.get_environment', $names);
        $this->assertContains('beacon.get_application_info', $names);
        $this->callTool()->assertJsonMissingPath('result.structuredContent');
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableHttp($app): void
    {
        parent::enableHttp($app);
        $app['config']->set('beacon.security.allowed_capabilities', ['environment']);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableHttpWithoutEnvironmentCapability($app): void
    {
        parent::enableHttp($app);
        $app['config']->set('beacon.security.allowed_capabilities', ['application']);
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
        $inspection = $this->application()->make(EnvironmentInspector::class)->inspect();
        $this->assertInstanceOf(EnvironmentInspection::class, $inspection);

        return $inspection->toArray();
    }

    private function invokeDirectly(Principal $principal): McpResponse
    {
        $response = (new GetEnvironmentTool())->handle($this->application()->make(Beacon::class), $principal);
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
    private function message(string $method = 'tools/call', array $params = ['name' => 'beacon.get_environment', 'arguments' => []]): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => (object) $params,
        ];
    }
}
