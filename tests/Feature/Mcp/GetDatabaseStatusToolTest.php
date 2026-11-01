<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Mcp;

use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Database\StatusProbe;
use Beacon\Events\AuthorizationEvaluated;
use Beacon\Inspectors\DatabaseInspector;
use Beacon\Mcp\ExecutionGate;
use Beacon\Mcp\Tools\GetDatabaseStatusTool;
use Beacon\Security\Principal;
use Beacon\Tests\Fixtures\FailingDatabaseInspector;
use Beacon\Tests\Fixtures\RecordingStatusProbe;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response as McpResponse;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The database status tool through the real, authenticated MCP HTTP lifecycle.
 */
#[Group('mcp')]
final class GetDatabaseStatusToolTest extends McpTestCase
{
    private const URL = 'https://localhost/beacon/mcp';

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function it_is_listed_as_a_read_only_tool_without_arguments(): void
    {
        $tools = (array) $this->rpc('tools/list')->assertOk()->json('result.tools');
        $tool = collect($tools)->firstWhere('name', 'beacon.get_database_status');

        $this->assertIsArray($tool);
        $this->assertSame('beacon.get_database_status', $tool['name']);
        $this->assertSame('Get database status', $tool['title']);

        $annotations = $tool['annotations'];
        $this->assertIsArray($annotations);
        $this->assertSame(
            ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            array_diff_key($annotations, ['title' => true]),
        );

        if (array_key_exists('title', $annotations)) {
            $this->assertSame('Get database status', $annotations['title']);
        }

        $this->assertSame([], (array) data_get($tool, 'inputSchema.properties'));
        $this->assertSame(
            ['schema_version', 'connection', 'driver', 'status', 'version', 'latency_ms', 'summary'],
            array_keys((array) data_get($tool, 'outputSchema.properties')),
        );
        $this->assertSame(
            ['schema_version', 'connection', 'driver', 'status', 'version', 'latency_ms', 'summary'],
            data_get($tool, 'outputSchema.required'),
        );
        $this->assertSame(
            ['connected', 'unavailable', 'unknown', 'unsupported'],
            data_get($tool, 'outputSchema.properties.status.enum'),
        );

        $description = $tool['description'];
        $this->assertIsString($description);
        $description = preg_replace('/\s+/', ' ', $description);
        $this->assertIsString($description);

        foreach ([
            'default',
            'MySQL',
            'MariaDB',
            'SQLite',
            'PostgreSQL',
            'SQL Server',
            'select 1',
            'BEACON_DATABASE_TIMEOUT',
            'Takes no arguments',
            '"database" capability',
            'schema',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $description);
        }
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function an_authorized_client_receives_the_database_status(): void
    {
        $probe = new RecordingStatusProbe();
        $this->application()->instance(StatusProbe::class, $probe);
        config()->set('database.connections.sqlite.password', 'database-password-not-in-output');
        config()->set('database.connections.sqlite.url', 'sqlite:///tmp/database-url-not-in-output.sqlite');

        $response = $this->callTool()->assertOk();
        $response->assertJsonPath('result.isError', false);
        $structured = $response->json('result.structuredContent');
        $this->assertIsArray($structured);
        $this->assertSame('connected', $structured['status']);
        $this->assertSame('sqlite', $structured['driver']);
        $this->assertSame('8.0.36', $structured['version']);
        $this->assertSame(1, $probe->calls);

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('database-password-not-in-output', $body);
        $this->assertStringNotContainsString('database-url-not-in-output', $body);
        $this->assertStringNotContainsString('db.internal', $body);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function arguments_are_rejected_without_echoing_them(): void
    {
        $logged = $this->captureLog();
        $response = $this->callTool(['connection' => 'probe-secret-not-in-output'])->assertOk();

        $response->assertJsonPath('result.isError', true);
        $response->assertJsonPath('result.content.0.text', 'Database status does not accept arguments.');
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('probe-secret-not-in-output', $body);
        $this->assertStringNotContainsString('probe-secret-not-in-output', $logged());
        $this->assertStringNotContainsString('InvalidDatabaseQueryException', $logged());
    }

    #[Test]
    #[DefineEnvironment('enableHttpWithoutDatabaseCapability')]
    public function the_execution_gate_rejects_a_caller_without_the_database_capability(): void
    {
        Event::fake([AuthorizationEvaluated::class]);

        $response = $this->callTool();

        $response->assertJsonPath('error.code', ExecutionGate::PERMISSION_DENIED);
        $response->assertJsonPath('error.message', ExecutionGate::DENIED_MESSAGE);
        $response->assertJsonMissingPath('result');

        Event::assertDispatched(AuthorizationEvaluated::class, static fn (AuthorizationEvaluated $event): bool => ! $event->allowed
            && $event->capability === 'database:read');
    }

    #[Test]
    #[DefineEnvironment('enableHttpWithoutDatabaseCapability')]
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
        $this->application()->instance(StatusProbe::class, new RecordingStatusProbe());
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
        $this->application()->make(InspectorRegistry::class)->register(DatabaseInspector::NAME, FailingDatabaseInspector::class);

        foreach ([true, false] as $debug) {
            config()->set('app.debug', $debug);

            $response = $this->callTool()->assertOk();

            $response->assertJsonPath('result.isError', true);
            $response->assertJsonPath('result.content.0.text', GetDatabaseStatusTool::UNAVAILABLE_MESSAGE);
            $response->assertJsonMissingPath('result.structuredContent');

            $body = (string) $response->getContent();

            foreach ([...FailingDatabaseInspector::DETAILS, 'database-inspector-secret', 'db.internal', 'RuntimeException', 'FailingDatabaseInspector'] as $detail) {
                $this->assertStringNotContainsString($detail, $body);
                $this->assertStringNotContainsString($detail, $logged());
            }
        }
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function a_disabled_inspector_removes_only_this_tool(): void
    {
        config()->set('beacon.inspectors.database.enabled', false);

        $names = array_column((array) $this->rpc('tools/list')->assertOk()->json('result.tools'), 'name');

        $this->assertNotContains('beacon.get_database_status', $names);
        $this->assertContains('beacon.get_application_info', $names);
        $this->assertContains('beacon.get_health', $names);
        $this->callTool()->assertJsonMissingPath('result.structuredContent');
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableHttp($app): void
    {
        parent::enableHttp($app);
        $app['config']->set('beacon.security.allowed_capabilities', ['database']);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableHttpWithoutDatabaseCapability($app): void
    {
        parent::enableHttp($app);
        $app['config']->set('beacon.security.allowed_capabilities', ['application']);
    }

    private function invokeDirectly(Principal $principal): McpResponse
    {
        $response = (new GetDatabaseStatusTool())->handle(
            $this->application()->make(Beacon::class),
            $principal,
            new Request([]),
        );
        $this->assertInstanceOf(McpResponse::class, $response);

        return $response;
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
     * @param array<string, mixed> $arguments
     *
     * @return TestResponse<Response>
     */
    private function callTool(array $arguments = []): TestResponse
    {
        return $this->postJson(self::URL, $this->message('tools/call', [
            'name' => 'beacon.get_database_status',
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
    private function message(string $method = 'tools/call', array $params = ['name' => 'beacon.get_database_status', 'arguments' => []]): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => (object) $params,
        ];
    }
}
