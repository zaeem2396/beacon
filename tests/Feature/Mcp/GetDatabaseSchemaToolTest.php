<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Mcp;

use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Events\AuthorizationEvaluated;
use Beacon\Health\Clock;
use Beacon\Inspectors\SchemaInspector;
use Beacon\Mcp\ExecutionGate;
use Beacon\Mcp\Tools\GetDatabaseSchemaTool;
use Beacon\Schema\SchemaReader;
use Beacon\Security\Principal;
use Beacon\Tests\Fixtures\FailingSchemaInspector;
use Beacon\Tests\Fixtures\FrozenClock;
use Beacon\Tests\Fixtures\RecordingSchemaReader;
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
 * The schema tool through the real, authenticated MCP HTTP lifecycle.
 */
#[Group('mcp')]
final class GetDatabaseSchemaToolTest extends McpTestCase
{
    private const URL = 'https://localhost/beacon/mcp';

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function it_is_listed_with_bounded_filters(): void
    {
        $tools = (array) $this->rpc('tools/list')->assertOk()->json('result.tools');
        $tool = collect($tools)->firstWhere('name', 'beacon.get_database_schema');

        $this->assertIsArray($tool);
        $this->assertSame('Get database schema', $tool['title']);
        $annotations = $tool['annotations'];
        $this->assertIsArray($annotations);
        $this->assertSame(
            ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            array_diff_key($annotations, ['title' => true]),
        );
        $this->assertSame(
            ['connection', 'table', 'include_views', 'include_columns', 'include_indexes', 'include_foreign_keys', 'page', 'limit'],
            array_keys((array) data_get($tool, 'inputSchema.properties')),
        );
        $this->assertSame(
            ['schema_version', 'connection', 'summary', 'pagination', 'objects', 'warnings'],
            array_keys((array) data_get($tool, 'outputSchema.properties')),
        );

        $description = $tool['description'];
        $this->assertIsString($description);
        $description = preg_replace('/\s+/', ' ', $description);
        $this->assertIsString($description);

        foreach (['MySQL', 'MariaDB', 'information_schema', 'does not return table rows', '"schema" capability', 'BEACON_SCHEMA_TIMEOUT'] as $phrase) {
            $this->assertStringContainsString($phrase, $description);
        }
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function an_authorized_client_receives_schema_metadata(): void
    {
        $this->application()->instance(Clock::class, new FrozenClock('2026-10-25T13:33:00+00:00'));
        $reader = new RecordingSchemaReader();
        $this->application()->instance(SchemaReader::class, $reader);
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.password', 'schema-password-not-in-output');
        config()->set('database.connections.mysql.url', 'mysql://beacon:schema-password-not-in-output@db.internal/app');

        $response = $this->callTool()->assertOk();
        $response->assertJsonPath('result.isError', false);
        $response->assertJsonPath('result.structuredContent.connection.status', 'connected');
        $response->assertJsonPath('result.structuredContent.connection.driver', 'mysql');
        $this->assertSame(1, $reader->calls);

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('schema-password-not-in-output', $body);
        $this->assertStringNotContainsString('db.internal', $body);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function arguments_are_rejected_without_echoing_them(): void
    {
        $logged = $this->captureLog();
        $response = $this->callTool(['table' => "orders'; drop table probe-secret-not-in-output"])->assertOk();

        $response->assertJsonPath('result.isError', true);
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('probe-secret-not-in-output', $body);
        $this->assertStringNotContainsString('drop table', $body);
        $this->assertStringNotContainsString('probe-secret-not-in-output', $logged());
    }

    #[Test]
    #[DefineEnvironment('enableHttpWithoutSchemaCapability')]
    public function the_database_capability_does_not_grant_schema_inspection(): void
    {
        $reader = new RecordingSchemaReader();
        $this->application()->instance(SchemaReader::class, $reader);
        Event::fake([AuthorizationEvaluated::class]);

        $response = $this->callTool();

        $response->assertJsonPath('error.code', ExecutionGate::PERMISSION_DENIED);
        $response->assertJsonPath('error.message', ExecutionGate::DENIED_MESSAGE);
        $this->assertSame(0, $reader->calls);
        Event::assertDispatched(AuthorizationEvaluated::class, static fn (AuthorizationEvaluated $event): bool => ! $event->allowed
            && $event->capability === 'schema:read');
    }

    #[Test]
    #[DefineEnvironment('enableHttpWithoutSchemaCapability')]
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
        $this->application()->instance(SchemaReader::class, new RecordingSchemaReader());
        config()->set('database.default', 'mysql');
        Event::fake([AuthorizationEvaluated::class]);

        $this->callTool()->assertOk()->assertJsonPath('result.isError', false);

        Event::assertDispatchedTimes(AuthorizationEvaluated::class, 2);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function unauthenticated_clients_are_rejected_before_the_tool_runs(): void
    {
        $reader = new RecordingSchemaReader();
        $this->application()->instance(SchemaReader::class, $reader);

        $this->postJson(self::URL, $this->message())->assertStatus(401)->assertJsonMissingPath('result');
        $this->assertSame(0, $reader->calls);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function inspection_failures_return_a_generic_error_without_internal_details(): void
    {
        $logged = $this->captureLog();
        $this->application()->make(InspectorRegistry::class)->register(SchemaInspector::NAME, FailingSchemaInspector::class);
        config()->set('app.debug', true);

        $response = $this->callTool()->assertOk();

        $response->assertJsonPath('result.isError', true);
        $response->assertJsonPath('result.content.0.text', GetDatabaseSchemaTool::UNAVAILABLE_MESSAGE);
        $body = (string) $response->getContent();

        foreach ([...FailingSchemaInspector::DETAILS, 'schema-inspector-secret', 'db.internal', 'FailingSchemaInspector'] as $detail) {
            $this->assertStringNotContainsString($detail, $body);
            $this->assertStringNotContainsString($detail, $logged());
        }
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function a_disabled_inspector_removes_only_this_tool(): void
    {
        config()->set('beacon.inspectors.schema.enabled', false);
        $names = array_column((array) $this->rpc('tools/list')->assertOk()->json('result.tools'), 'name');

        $this->assertNotContains('beacon.get_database_schema', $names);
        $this->assertContains('beacon.get_database_status', $names);
        $this->callTool()->assertJsonMissingPath('result.structuredContent');
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableHttp($app): void
    {
        parent::enableHttp($app);
        $app['config']->set('beacon.security.allowed_capabilities', ['schema']);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableHttpWithoutSchemaCapability($app): void
    {
        parent::enableHttp($app);
        $app['config']->set('beacon.security.allowed_capabilities', ['database']);
    }

    private function invokeDirectly(Principal $principal): McpResponse
    {
        $response = (new GetDatabaseSchemaTool())->handle(
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
            'name' => 'beacon.get_database_schema',
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
    private function message(string $method = 'tools/call', array $params = ['name' => 'beacon.get_database_schema', 'arguments' => []]): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => (object) $params,
        ];
    }
}
