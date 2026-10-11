<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Mcp;

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
        $this->assertSame([], data_get($responses[1], 'result.tools'));
    }

    #[Test]
    public function it_refuses_to_start_when_mcp_is_disabled(): void
    {
        $process = $this->start(['APP_ENV' => 'testing'], []);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('[beacon] not found', $process->getOutput() . $process->getErrorOutput());
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
