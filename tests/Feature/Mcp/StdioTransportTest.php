<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Mcp;

use Beacon\Mcp\ExecutionGate;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Starts the server through "php artisan mcp:start beacon" (via the Testbench
 * CLI and testbench.yaml) and talks to it over real stdin/stdout.
 */
#[Group('mcp')]
final class StdioTransportTest extends TestCase
{
    private const ENABLED = [
        'APP_ENV' => 'testing',
        'BEACON_MCP_ENABLED' => 'true',
        'BEACON_MCP_ENVIRONMENTS' => 'testing',
        'BEACON_MCP_TOKEN' => McpTestCase::TOKEN,
    ];

    private const INITIALIZE = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
        'protocolVersion' => '2025-06-18',
        'capabilities' => [],
        'clientInfo' => ['name' => 'beacon-tests', 'version' => '1.0.0'],
    ]];

    private const APPLICATION_TOOL = 'beacon.get_application_info';

    private const CALL_APPLICATION_TOOL = ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
        'name' => self::APPLICATION_TOOL,
        'arguments' => [],
    ]];

    #[Test]
    public function it_speaks_json_rpc_over_stdio_when_enabled(): void
    {
        $process = $this->start(self::ENABLED, [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'beacon-tests', 'version' => '1.0.0'],
            ]],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new \stdClass()],
        ]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $responses = array_map(
            static fn (string $line): mixed => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", $process->getOutput()))),
        );

        $this->assertCount(2, $responses);
        $this->assertSame('Laravel Beacon', data_get($responses[0], 'result.serverInfo.name'));
        $this->assertSame([self::APPLICATION_TOOL, 'beacon.get_environment'], array_column((array) data_get($responses[1], 'result.tools'), 'name'));
    }

    #[Test]
    public function the_application_tool_runs_over_stdio_when_its_capability_is_allowed(): void
    {
        $process = $this->start([...self::ENABLED, 'BEACON_ALLOWED_CAPABILITIES' => 'application'], [self::INITIALIZE, self::CALL_APPLICATION_TOOL]);
        $responses = $this->responses($process);

        $this->assertFalse(data_get($responses[1], 'result.isError'));
        $this->assertSame('testing', data_get($responses[1], 'result.structuredContent.environment'));
        $this->assertSame(PHP_VERSION, data_get($responses[1], 'result.structuredContent.php_version'));
        $this->assertStringNotContainsString(McpTestCase::TOKEN, $process->getOutput());
    }

    #[Test]
    public function the_application_tool_is_denied_over_stdio_without_its_capability(): void
    {
        $responses = $this->responses($this->start(self::ENABLED, [self::INITIALIZE, self::CALL_APPLICATION_TOOL]));

        $this->assertSame(ExecutionGate::PERMISSION_DENIED, data_get($responses[1], 'error.code'));
        $this->assertNull(data_get($responses[1], 'result'));
    }

    #[Test]
    public function it_refuses_to_start_when_mcp_is_disabled(): void
    {
        $process = $this->start(['APP_ENV' => 'testing'], []);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('[beacon] not found', $process->getOutput() . $process->getErrorOutput());
    }

    /**
     * @return list<mixed>
     */
    private function responses(Process $process): array
    {
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return array_map(
            static fn (string $line): mixed => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", $process->getOutput()))),
        );
    }

    /**
     * @param array<string, string> $env
     * @param list<array<string, mixed>> $messages
     */
    private function start(array $env, array $messages): Process
    {
        $input = implode('', array_map(
            static fn (array $message): string => json_encode($message, JSON_THROW_ON_ERROR) . "\n",
            $messages,
        ));

        $process = new Process(
            [PHP_BINARY, 'vendor/bin/testbench', 'mcp:start', 'beacon'],
            dirname(__DIR__, 3),
            $env,
            $input,
            60,
        );

        $process->run();

        return $process;
    }
}
