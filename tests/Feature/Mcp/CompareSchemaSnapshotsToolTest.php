<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Mcp;

use Beacon\Mcp\ExecutionGate;
use Beacon\Tests\Fixtures\RecordingSchemaReader;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * Schema snapshot tools through the MCP HTTP lifecycle.
 */
#[Group('mcp')]
final class CompareSchemaSnapshotsToolTest extends McpTestCase
{
    private const URL = 'https://localhost/beacon/mcp';

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function snapshot_and_compare_are_listed_as_read_only(): void
    {
        $tools = (array) $this->rpc('tools/list')->assertOk()->json('result.tools');
        $create = collect($tools)->firstWhere('name', 'beacon.create_schema_snapshot');
        $compare = collect($tools)->firstWhere('name', 'beacon.compare_schema_snapshots');

        $this->assertIsArray($create);
        $this->assertIsArray($compare);
        $createAnnotations = $create['annotations'] ?? null;
        $compareAnnotations = $compare['annotations'] ?? null;
        $this->assertIsArray($createAnnotations);
        $this->assertIsArray($compareAnnotations);
        $this->assertTrue($createAnnotations['readOnlyHint']);
        $this->assertTrue($compareAnnotations['readOnlyHint']);
        $this->assertArrayNotHasKey('sql', (array) data_get($compare, 'inputSchema.properties'));
    }

    #[Test]
    #[DefineEnvironment('enableCompare')]
    public function an_authorized_comparison_does_not_read_the_database(): void
    {
        $reader = $this->reader();
        $json = $this->snapshot('orders');
        $response = $this->rpc('tools/call', [
            'name' => 'beacon.compare_schema_snapshots',
            'arguments' => [
                'baseline' => $json,
                'candidate' => $this->snapshot('legacy'),
                'severity' => 'critical',
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('result.structuredContent.differences.0.id', 'table.orders.removed');
        $response->assertJsonPath('result.structuredContent.differences.0.severity', 'critical');
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertSame(0, $reader->calls);
        $this->assertStringNotContainsString('ci-only', $content);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function missing_capabilities_are_denied_before_schema_introspection(): void
    {
        $reader = $this->reader();
        $this->rpc('tools/call', [
            'name' => 'beacon.create_schema_snapshot',
            'arguments' => [],
        ])->assertJsonPath('error.code', ExecutionGate::PERMISSION_DENIED);

        $this->rpc('tools/call', [
            'name' => 'beacon.compare_schema_snapshots',
            'arguments' => [
                'baseline_live' => true,
                'candidate' => $this->snapshot('orders'),
            ],
        ])->assertJsonPath('error.code', ExecutionGate::PERMISSION_DENIED);

        $this->assertSame(0, $reader->calls);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableCompare($app): void
    {
        $this->enableHttp($app);
        $app['config']->set('beacon.security.allowed_capabilities', ['schema_compare']);
    }

    private function reader(): RecordingSchemaReader
    {
        $reader = new RecordingSchemaReader();
        $this->application()->instance(\Beacon\Schema\SchemaReader::class, $reader);

        return $reader;
    }

    private function snapshot(string $table): string
    {
        return (string) json_encode([
            'format_version' => 1,
            'metadata' => [
                'beacon_version' => 'unknown',
                'laravel_version' => '13.0.0',
                'driver' => 'mysql',
                'server_version' => '8.4.0',
                'database' => 'app',
                'source' => 'snapshot',
                'identifier' => '0123456789abcdef',
                'created_at' => '2026-01-01T00:00:00+00:00',
                'complete' => true,
                'columns_included' => true,
                'indexes_included' => true,
                'references_included' => true,
                'views_included' => true,
            ],
            'objects' => [[
                'name' => $table,
                'type' => 'table',
                'columns' => [],
                'primary_columns' => [],
                'indexes' => [],
                'references' => [],
            ]],
        ]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return TestResponse<Response>
     */
    private function rpc(string $method, array $params = []): TestResponse
    {
        return $this->postJson(self::URL, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ], ['Authorization' => 'Bearer ' . self::TOKEN]);
    }
}
