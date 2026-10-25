<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Mcp;

use Beacon\Mcp\BeaconServer;
use Beacon\Mcp\McpRegistry;
use Beacon\Mcp\McpUnavailableException;
use Beacon\Tests\Fixtures\LeakyInspector;
use Beacon\Tests\Fixtures\Mcp\EchoTool;
use Beacon\Tests\Fixtures\Mcp\RecordingTransport;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('mcp')]
final class ProtocolTest extends McpTestCase
{
    /**
     * Literal names: constant expressions are evaluated while PHPUnit builds
     * the suite, where tool classes cannot load without laravel/mcp.
     */
    private const BUILT_IN_TOOLS = ['beacon.get_application_info', 'beacon.get_environment', 'beacon.get_routes', 'beacon.get_config'];

    #[Test]
    #[DefineEnvironment('enableMcp')]
    public function it_completes_the_initialize_handshake(): void
    {
        $response = $this->startServer()->request('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'beacon-tests', 'version' => '1.0.0'],
        ]);

        $this->assertSame('2.0', $response['jsonrpc'] ?? null);
        $this->assertSame(1, $response['id'] ?? null);
        $this->assertSame('2025-06-18', data_get($response, 'result.protocolVersion'));
        $this->assertSame('Laravel Beacon', data_get($response, 'result.serverInfo.name'));
        $this->assertIsArray(data_get($response, 'result.capabilities.tools'));
    }

    #[Test]
    #[DefineEnvironment('enableMcp')]
    public function it_answers_ping(): void
    {
        $response = $this->startServer()->request('ping');

        $this->assertArrayHasKey('result', $response);
        $this->assertArrayNotHasKey('error', $response);
    }

    #[Test]
    #[DefineEnvironment('enableMcp')]
    public function it_exposes_only_beacons_built_in_tools(): void
    {
        $transport = $this->startServer();

        $this->assertSame(self::BUILT_IN_TOOLS, $this->toolNames($transport));
        $this->assertSame([], data_get($transport->request('resources/list'), 'result.resources'));
        $this->assertSame([], data_get($transport->request('prompts/list'), 'result.prompts'));
    }

    #[Test]
    #[DefineEnvironment('enableMcp')]
    public function registered_inspectors_are_not_exposed_as_tools(): void
    {
        $this->application()->make(\Beacon\Core\InspectorRegistry::class)->register('leaky', LeakyInspector::class);

        $this->assertSame(self::BUILT_IN_TOOLS, $this->toolNames($this->startServer()));
    }

    #[Test]
    #[DefineEnvironment('enableMcp')]
    public function unknown_tools_cannot_be_called(): void
    {
        $response = $this->startServer()->request('tools/call', ['name' => 'get_config', 'arguments' => (object) []]);

        $this->assertArrayHasKey('error', $response);
        $this->assertArrayNotHasKey('result', $response);
    }

    #[Test]
    #[DefineEnvironment('enableMcp')]
    public function explicitly_registered_tools_are_exposed(): void
    {
        $this->application()->make(McpRegistry::class)->tool(EchoTool::class);

        $this->assertSame([...self::BUILT_IN_TOOLS, 'echo-tool'], $this->toolNames($this->startServer()));
    }

    #[Test]
    public function the_server_refuses_to_start_when_mcp_is_disabled(): void
    {
        $server = $this->application()->make(BeaconServer::class, ['transport' => new RecordingTransport()]);

        $this->expectException(McpUnavailableException::class);

        $server->start();
    }

    /**
     * @return list<mixed>
     */
    private function toolNames(RecordingTransport $transport): array
    {
        $tools = data_get($transport->request('tools/list'), 'result.tools');
        $this->assertIsArray($tools);

        return array_column($tools, 'name');
    }

    private function startServer(): RecordingTransport
    {
        $transport = new RecordingTransport();

        $this->application()->make(BeaconServer::class, ['transport' => $transport])->start();

        return $transport;
    }
}
