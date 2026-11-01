<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Mcp;

use Beacon\Core\Beacon;
use Beacon\Events\AuthorizationEvaluated;
use Beacon\Mcp\ExecutionGate;
use Beacon\Mcp\Tools\AnalyzeMigrationRisksTool;
use Beacon\Migrations\MigrationHistory;
use Beacon\Security\Principal;
use Beacon\Tests\Fixtures\RecordingMigrationHistory;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response as McpResponse;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The migration-risk tool through the real MCP HTTP lifecycle.
 */
#[Group('mcp')]
final class AnalyzeMigrationRisksToolTest extends McpTestCase
{
    private const URL = 'https://localhost/beacon/mcp';

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function it_is_listed_as_read_only(): void
    {
        $tools = (array) $this->rpc('tools/list')->assertOk()->json('result.tools');
        $tool = collect($tools)->firstWhere('name', 'beacon.analyze_migration_risks');

        $this->assertIsArray($tool);
        $this->assertSame('Analyze migration risks', $tool['title']);
        $annotations = $tool['annotations'];
        $this->assertIsArray($annotations);
        $this->assertSame(
            ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            array_diff_key($annotations, ['title' => true]),
        );
        $this->assertSame(
            ['connection', 'migration', 'path', 'category', 'severity', 'pending', 'include_executed', 'page', 'limit'],
            array_keys((array) data_get($tool, 'inputSchema.properties')),
        );
        $this->assertArrayNotHasKey('enrich', (array) data_get($tool, 'inputSchema.properties'));
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function an_authorized_client_receives_findings_without_executing_php(): void
    {
        $history = new RecordingMigrationHistory();
        $this->application()->instance(MigrationHistory::class, $history);
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.driver', 'mysql');
        config()->set('database.connections.mysql.password', 'migration-password-not-in-output');
        $path = $this->application()->databasePath('migrations') . '/2099_02_01_000000_beacon_risk_mcp.php';
        $marker = $this->application()->databasePath('migrations') . '/ran.txt';

        try {
            file_put_contents($path, <<<'PHP'
                <?php

                file_put_contents(__DIR__ . '/ran.txt', 'migration-php-was-read');

                class BeaconRiskMcpProbe
                {
                    public function up(): void
                    {
                        file_put_contents(__DIR__ . '/ran.txt', 'migration-php-was-read');
                        Schema::dropIfExists('orders');
                    }

                    public function down(): void
                    {
                    }
                }
                PHP);

            $response = $this->callTool(['severity' => 'critical', 'enrich' => true])->assertOk();
            $response->assertJsonPath('result.isError', false);
            $response->assertJsonPath('result.structuredContent.scope.enriched', false);
            $response->assertJsonPath('result.structuredContent.findings.0.rule_id', 'MIG001');
            $response->assertJsonPath('result.structuredContent.findings.0.severity', 'critical');
            $this->assertSame(1, $history->calls);
            $this->assertFileDoesNotExist($marker);
            $this->assertFalse(class_exists('BeaconRiskMcpProbe', false));
            $body = (string) $response->getContent();
            $this->assertStringNotContainsString('migration-password-not-in-output', $body);
            $this->assertStringNotContainsString('migration-php-was-read', $body);
        } finally {
            @unlink($path);
            @unlink($marker);
        }
    }

    #[Test]
    #[DefineEnvironment('enableHttpWithoutRiskCapability')]
    public function migrations_and_schema_do_not_grant_risk_analysis(): void
    {
        $history = new RecordingMigrationHistory();
        $this->application()->instance(MigrationHistory::class, $history);

        $response = $this->callTool();

        $response->assertJsonPath('error.code', ExecutionGate::PERMISSION_DENIED);
        $response->assertJsonPath('error.message', ExecutionGate::DENIED_MESSAGE);
        $this->assertSame(0, $history->calls);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function a_successful_call_checks_schema_separately(): void
    {
        $this->application()->instance(MigrationHistory::class, new RecordingMigrationHistory());
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.driver', 'mysql');
        Event::fake([AuthorizationEvaluated::class]);

        $this->callTool()->assertOk()->assertJsonPath('result.isError', false);

        Event::assertDispatched(AuthorizationEvaluated::class, static fn (AuthorizationEvaluated $event): bool => $event->capability === 'migration_risk:read' && $event->allowed);
        Event::assertDispatched(AuthorizationEvaluated::class, static fn (AuthorizationEvaluated $event): bool => $event->capability === 'schema:read' && ! $event->allowed);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function arguments_are_rejected_without_echoing_them(): void
    {
        $response = $this->callTool(['path' => '../risk-path-not-in-output', 'sql' => 'drop table orders'])->assertOk();

        $response->assertJsonPath('result.isError', true);
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('risk-path-not-in-output', $body);
        $this->assertStringNotContainsString('drop table', $body);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function unauthenticated_clients_are_rejected(): void
    {
        $history = new RecordingMigrationHistory();
        $this->application()->instance(MigrationHistory::class, $history);

        $this->postJson(self::URL, $this->message())->assertStatus(401);
        $this->assertSame(0, $history->calls);
    }

    #[Test]
    public function disabled_mcp_does_not_expose_the_tool(): void
    {
        $this->postJson(self::URL, $this->message())->assertNotFound();
    }

    #[Test]
    #[DefineEnvironment('enableHttpWithoutRiskCapability')]
    public function direct_invocation_still_requires_the_capability(): void
    {
        $history = new RecordingMigrationHistory();
        $this->application()->instance(MigrationHistory::class, $history);
        $response = (new AnalyzeMigrationRisksTool())->handle(
            $this->application()->make(Beacon::class),
            new Principal('beacon-token:current', 'bearer-token'),
            $this->application()->make(\Beacon\Contracts\Authorizer::class),
            new Request([]),
        );

        $this->assertInstanceOf(McpResponse::class, $response);
        $this->assertTrue($response->isError());
        $this->assertSame(ExecutionGate::DENIED_MESSAGE, (string) $response->content());
        $this->assertSame(0, $history->calls);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableHttp($app): void
    {
        parent::enableHttp($app);
        $app['config']->set('beacon.security.allowed_capabilities', ['migration_risk']);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableHttpWithoutRiskCapability($app): void
    {
        parent::enableHttp($app);
        $app['config']->set('beacon.security.allowed_capabilities', ['migrations', 'schema', 'database']);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return TestResponse<Response>
     */
    private function callTool(array $arguments = []): TestResponse
    {
        return $this->postJson(self::URL, $this->message('tools/call', [
            'name' => 'beacon.analyze_migration_risks',
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
    private function message(string $method = 'tools/call', array $params = ['name' => 'beacon.analyze_migration_risks', 'arguments' => []]): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => (object) $params,
        ];
    }
}
