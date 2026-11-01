<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Mcp;

use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Events\AuthorizationEvaluated;
use Beacon\Inspectors\MigrationInspector;
use Beacon\Mcp\ExecutionGate;
use Beacon\Mcp\Tools\GetMigrationsTool;
use Beacon\Migrations\MigrationHistory;
use Beacon\Migrations\MigrationScanner;
use Beacon\Security\Principal;
use Beacon\Tests\Fixtures\FailingMigrationInspector;
use Beacon\Tests\Fixtures\RecordingMigrationHistory;
use Beacon\Tests\Fixtures\RecordingMigrationScanner;
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
 * The migrations tool through the real, authenticated MCP HTTP lifecycle.
 */
#[Group('mcp')]
final class GetMigrationsToolTest extends McpTestCase
{
    private const URL = 'https://localhost/beacon/mcp';

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function it_is_listed_with_bounded_filters(): void
    {
        $tools = (array) $this->rpc('tools/list')->assertOk()->json('result.tools');
        $tool = collect($tools)->firstWhere('name', 'beacon.get_migrations');

        $this->assertIsArray($tool);
        $this->assertSame('Get migrations', $tool['title']);
        $annotations = $tool['annotations'];
        $this->assertIsArray($annotations);
        $this->assertSame(
            ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            array_diff_key($annotations, ['title' => true]),
        );
        $this->assertSame(
            ['connection', 'name', 'status', 'batch', 'path', 'page', 'limit'],
            array_keys((array) data_get($tool, 'inputSchema.properties')),
        );
        $this->assertSame(
            ['schema_version', 'connection', 'repository', 'summary', 'scope', 'pagination', 'migrations', 'batches', 'warnings'],
            array_keys((array) data_get($tool, 'outputSchema.properties')),
        );

        $description = $tool['description'];
        $this->assertIsString($description);
        $description = preg_replace('/\s+/', ' ', $description);
        $this->assertIsString($description);

        foreach (['MySQL', 'MariaDB', 'SQLite', 'does not run migrations', '"migrations" capability', 'BEACON_MIGRATIONS_TIMEOUT'] as $phrase) {
            $this->assertStringContainsString($phrase, $description);
        }
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function an_authorized_client_receives_migration_metadata(): void
    {
        $history = new RecordingMigrationHistory();
        $this->application()->instance(MigrationHistory::class, $history);
        $this->application()->instance(MigrationScanner::class, new RecordingMigrationScanner());
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.password', 'migration-password-not-in-output');
        config()->set('database.connections.mysql.url', 'mysql://beacon:migration-password-not-in-output@db.internal/app');

        $response = $this->callTool()->assertOk();
        $response->assertJsonPath('result.isError', false);
        $response->assertJsonPath('result.structuredContent.connection.status', 'connected');
        $response->assertJsonPath('result.structuredContent.repository.table', 'migrations');
        $response->assertJsonPath('result.structuredContent.repository.empty', true);
        $this->assertSame(1, $history->calls);

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('migration-password-not-in-output', $body);
        $this->assertStringNotContainsString('db.internal', $body);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function arguments_are_rejected_without_echoing_them(): void
    {
        $logged = $this->captureLog();
        $response = $this->callTool(['path' => '../migration-path-not-in-output', 'sql' => 'drop table migrations'])->assertOk();

        $response->assertJsonPath('result.isError', true);
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('migration-path-not-in-output', $body);
        $this->assertStringNotContainsString('drop table', $body);
        $this->assertStringNotContainsString('migration-path-not-in-output', $logged());
    }

    #[Test]
    #[DefineEnvironment('enableHttpWithoutMigrationsCapability')]
    public function the_schema_capability_does_not_grant_migration_inspection(): void
    {
        $history = new RecordingMigrationHistory();
        $scanner = new RecordingMigrationScanner();
        $this->application()->instance(MigrationHistory::class, $history);
        $this->application()->instance(MigrationScanner::class, $scanner);
        Event::fake([AuthorizationEvaluated::class]);

        $response = $this->callTool();

        $response->assertJsonPath('error.code', ExecutionGate::PERMISSION_DENIED);
        $response->assertJsonPath('error.message', ExecutionGate::DENIED_MESSAGE);
        $this->assertSame(0, $history->calls);
        $this->assertSame(0, $scanner->calls);
        Event::assertDispatched(AuthorizationEvaluated::class, static fn (AuthorizationEvaluated $event): bool => ! $event->allowed
            && $event->capability === 'migrations:read');
    }

    #[Test]
    #[DefineEnvironment('enableHttpWithoutMigrationsCapability')]
    public function the_tool_enforces_its_capability_even_if_the_execution_gate_is_bypassed(): void
    {
        $history = new RecordingMigrationHistory();
        $this->application()->instance(MigrationHistory::class, $history);

        $response = $this->invokeDirectly(new Principal('beacon-token:current', 'bearer-token'));

        $this->assertTrue($response->isError());
        $this->assertSame(ExecutionGate::DENIED_MESSAGE, (string) $response->content());
        $this->assertSame(0, $history->calls);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function a_successful_call_is_authorized_twice(): void
    {
        $this->application()->instance(MigrationHistory::class, new RecordingMigrationHistory());
        $this->application()->instance(MigrationScanner::class, new RecordingMigrationScanner());
        config()->set('database.default', 'mysql');
        Event::fake([AuthorizationEvaluated::class]);

        $this->callTool()->assertOk()->assertJsonPath('result.isError', false);

        Event::assertDispatchedTimes(AuthorizationEvaluated::class, 2);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function unauthenticated_clients_are_rejected_before_the_tool_runs(): void
    {
        $history = new RecordingMigrationHistory();
        $this->application()->instance(MigrationHistory::class, $history);

        $this->postJson(self::URL, $this->message())->assertStatus(401)->assertJsonMissingPath('result');
        $this->assertSame(0, $history->calls);
    }

    #[Test]
    public function disabled_mcp_does_not_expose_the_tool(): void
    {
        $history = new RecordingMigrationHistory();
        $this->application()->instance(MigrationHistory::class, $history);

        $this->postJson(self::URL, $this->message())->assertNotFound();
        $this->assertSame(0, $history->calls);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function inspection_failures_return_a_generic_error_without_internal_details(): void
    {
        $logged = $this->captureLog();
        $this->application()->make(InspectorRegistry::class)->register(MigrationInspector::NAME, FailingMigrationInspector::class);
        config()->set('app.debug', true);

        $response = $this->callTool()->assertOk();

        $response->assertJsonPath('result.isError', true);
        $response->assertJsonPath('result.content.0.text', GetMigrationsTool::UNAVAILABLE_MESSAGE);
        $body = (string) $response->getContent();

        foreach ([...FailingMigrationInspector::DETAILS, 'migration-inspector-secret', 'db.internal', 'FailingMigrationInspector'] as $detail) {
            $this->assertStringNotContainsString($detail, $body);
            $this->assertStringNotContainsString($detail, $logged());
        }
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function a_disabled_inspector_removes_only_this_tool(): void
    {
        config()->set('beacon.inspectors.migrations.enabled', false);
        $names = array_column((array) $this->rpc('tools/list')->assertOk()->json('result.tools'), 'name');

        $this->assertNotContains('beacon.get_migrations', $names);
        $this->assertContains('beacon.get_database_schema', $names);
        $this->callTool()->assertJsonMissingPath('result.structuredContent');
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function valid_filters_are_passed_to_the_repository_reader(): void
    {
        $history = new RecordingMigrationHistory();
        $this->application()->instance(MigrationHistory::class, $history);
        $this->application()->instance(MigrationScanner::class, new RecordingMigrationScanner());
        config()->set('database.default', 'mysql');

        $response = $this->callTool([
            'name' => 'orders',
            'status' => 'pending',
            'batch' => 2,
            'path' => 'database/migrations',
            'page' => 1,
            'limit' => 10,
        ])->assertOk();

        $response->assertJsonPath('result.isError', false);
        $response->assertJsonPath('result.structuredContent.scope.filtered', true);
        $response->assertJsonPath('result.structuredContent.scope.name', 'orders');
        $response->assertJsonPath('result.structuredContent.scope.status', 'pending');
        $response->assertJsonPath('result.structuredContent.pagination.per_page', 10);
        $this->assertSame(1, $history->calls);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableHttp($app): void
    {
        parent::enableHttp($app);
        $app['config']->set('beacon.security.allowed_capabilities', ['migrations']);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableHttpWithoutMigrationsCapability($app): void
    {
        parent::enableHttp($app);
        $app['config']->set('beacon.security.allowed_capabilities', ['database', 'schema']);
    }

    private function invokeDirectly(Principal $principal): McpResponse
    {
        $response = (new GetMigrationsTool())->handle(
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
            'name' => 'beacon.get_migrations',
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
    private function message(string $method = 'tools/call', array $params = ['name' => 'beacon.get_migrations', 'arguments' => []]): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => (object) $params,
        ];
    }
}
