<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Mcp;

use Beacon\Diagnostics\DiagnosticReport;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;

/**
 * beacon:check with laravel/mcp installed and registered.
 */
#[Group('mcp')]
final class CheckCommandTest extends McpTestCase
{
    #[Test]
    #[DefineEnvironment('enableMcp')]
    public function an_enabled_mcp_server_is_reported_as_operational(): void
    {
        [$exitCode, $statuses] = $this->check();

        $this->assertSame(DiagnosticReport::EXIT_OK, $exitCode);
        $this->assertSame('pass', $statuses['mcp']);
        $this->assertSame('pass', $statuses['mcp.sdk']);
        $this->assertSame('pass', $statuses['mcp.server']);
        $this->assertSame('pass', $statuses['auth']);
        $this->assertSame('disabled', $statuses['mcp.http']);
        $this->assertSame('pass', $statuses['security.capabilities']);
        $this->assertSame('not_implemented', $statuses['mcp.tools']);
    }

    #[Test]
    public function a_disabled_mcp_server_is_reported_as_disabled(): void
    {
        [$exitCode, $statuses] = $this->check();

        $this->assertSame(DiagnosticReport::EXIT_OK, $exitCode);
        $this->assertSame('disabled', $statuses['mcp']);
        $this->assertSame('pass', $statuses['mcp.sdk']);
        $this->assertSame('disabled', $statuses['mcp.server']);
    }

    #[Test]
    #[DefineEnvironment('enableMcpElsewhere')]
    public function an_environment_outside_the_allowlist_is_reported_as_disabled(): void
    {
        [$exitCode, $statuses] = $this->check();

        $this->assertSame(DiagnosticReport::EXIT_OK, $exitCode);
        $this->assertSame('disabled', $statuses['mcp']);
        $this->assertSame('disabled', $statuses['mcp.server']);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function the_http_transport_is_reported_when_enabled(): void
    {
        [$exitCode, $statuses] = $this->check();

        $this->assertSame(DiagnosticReport::EXIT_OK, $exitCode);
        $this->assertSame('pass', $statuses['mcp.http']);
    }

    #[Test]
    #[DefineEnvironment('enableInsecureHttp')]
    public function plain_http_is_a_warning(): void
    {
        [$exitCode, $statuses] = $this->check();

        $this->assertSame(DiagnosticReport::EXIT_OK, $exitCode);
        $this->assertSame('warn', $statuses['mcp.http']);
    }

    #[Test]
    public function json_goes_to_stdout_and_nothing_to_stderr(): void
    {
        $ok = $this->runProcess([]);
        $misconfigured = $this->runProcess(['BEACON_MCP_ENABLED' => 'true', 'BEACON_MCP_TOKEN' => 'short-process-secret']);

        foreach ([[$ok, 0, 'ok'], [$misconfigured, 1, 'misconfigured']] as [$process, $exitCode, $status]) {
            $this->assertSame($exitCode, $process->getExitCode(), $process->getErrorOutput());
            $this->assertSame('', $process->getErrorOutput());

            $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($report);
            $this->assertSame($status, $report['status']);
            $this->assertSame($exitCode, $report['exit_code']);
        }

        $this->assertStringNotContainsString('short-process-secret', $misconfigured->getOutput());
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableMcpElsewhere($app): void
    {
        $this->enableMcp($app);
        $app['config']->set('beacon.mcp.allowed_environments', ['local']);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableInsecureHttp($app): void
    {
        $this->enableHttp($app);
        $app['config']->set('beacon.mcp.http.require_https', false);
    }

    /**
     * @return array{int, array<string, string>}
     */
    private function check(): array
    {
        $exitCode = Artisan::call('beacon:check', ['--json' => true]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($report);
        $this->assertIsArray($report['checks']);

        $statuses = [];

        foreach ($report['checks'] as $check) {
            $this->assertIsArray($check);
            $this->assertIsString($check['id']);
            $this->assertIsString($check['status']);
            $statuses[$check['id']] = $check['status'];
        }

        return [$exitCode, $statuses];
    }

    /**
     * @param array<string, string> $env
     */
    private function runProcess(array $env): Process
    {
        $process = new Process(
            [PHP_BINARY, 'vendor/bin/testbench', 'beacon:check', '--json'],
            dirname(__DIR__, 3),
            ['APP_ENV' => 'testing', 'BEACON_MCP_ENVIRONMENTS' => 'testing', ...$env],
            null,
            60,
        );

        $process->run();

        return $process;
    }
}
