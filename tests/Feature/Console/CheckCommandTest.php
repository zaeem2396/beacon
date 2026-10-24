<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Console;

use Beacon\Contracts\Redactor;
use Beacon\Core\BeaconConfig;
use Beacon\Core\Version;
use Beacon\Diagnostics\DiagnosticReport;
use Beacon\Diagnostics\Diagnostics;
use Beacon\Diagnostics\DiagnosticStatus;
use Beacon\Tests\Fixtures\ThrowingRedactor;
use Beacon\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

final class CheckCommandTest extends TestCase
{
    private const TOKEN = 'beacon-test-token-0123456789abcdef0123';

    private const PREVIOUS_TOKEN = 'beacon-previous-token-0123456789abcdef';

    private const CHECK_IDS = [
        'package',
        'config',
        'application',
        'mcp',
        'mcp.sdk',
        'mcp.server',
        'mcp.http',
        'auth',
        'security.read_only',
        'security.capabilities',
        'security.redaction',
        'inspectors',
        'mcp.tools',
    ];

    #[Test]
    public function the_default_configuration_passes(): void
    {
        [$exitCode, $report] = $this->check();

        $this->assertSame(DiagnosticReport::EXIT_OK, $exitCode);
        $this->assertSame('ok', $report['status']);
        $this->assertSame('pass', $this->statusOf($report, 'package'));
        $this->assertSame('pass', $this->statusOf($report, 'config'));
        $this->assertSame('disabled', $this->statusOf($report, 'mcp'));
        $this->assertSame('disabled', $this->statusOf($report, 'mcp.server'));
        $this->assertSame('disabled', $this->statusOf($report, 'mcp.http'));
        $this->assertSame('disabled', $this->statusOf($report, 'auth'));
        $this->assertSame('pass', $this->statusOf($report, 'security.read_only'));
        $this->assertSame('pass', $this->statusOf($report, 'security.capabilities'));
        $this->assertSame('pass', $this->statusOf($report, 'security.redaction'));
    }

    #[Test]
    public function enabled_inspectors_are_reported(): void
    {
        [, $report] = $this->check();

        $this->assertSame('pass', $this->statusOf($report, 'inspectors'));
        $this->assertSame('Enabled: application, environment, routes, config.', $this->messageOf($report, 'inspectors'));
    }

    #[Test]
    public function disabling_every_inspector_is_reported(): void
    {
        config()->set('beacon.inspectors.application.enabled', false);
        config()->set('beacon.inspectors.environment.enabled', false);
        config()->set('beacon.inspectors.routes.enabled', false);
        config()->set('beacon.inspectors.config.enabled', false);

        [$exitCode, $report] = $this->check();

        $this->assertSame(DiagnosticReport::EXIT_OK, $exitCode);
        $this->assertSame('disabled', $this->statusOf($report, 'inspectors'));
    }

    #[Test]
    public function the_json_output_follows_the_documented_schema(): void
    {
        [$exitCode, $report] = $this->check();

        $this->assertSame(['schema_version', 'beacon_version', 'status', 'exit_code', 'summary', 'checks'], array_keys($report));
        $this->assertSame(1, $report['schema_version']);
        $this->assertSame(Version::current(), $report['beacon_version']);
        $this->assertSame($exitCode, $report['exit_code']);

        $statuses = array_column(DiagnosticStatus::cases(), 'value');
        $this->assertIsArray($report['summary']);
        $this->assertSame($statuses, array_keys($report['summary']));

        $this->assertIsArray($report['checks']);
        $this->assertSame(self::CHECK_IDS, array_column($report['checks'], 'id'));

        foreach ($report['checks'] as $check) {
            $this->assertIsArray($check);
            $this->assertSame(['id', 'name', 'status', 'message', 'hint'], array_keys($check));
            $this->assertContains($check['status'], $statuses);
            $this->assertIsString($check['name']);
            $this->assertIsString($check['message']);
            $this->assertTrue($check['hint'] === null || is_string($check['hint']));
        }

        $counts = array_count_values(array_column($report['checks'], 'status'));

        foreach ($statuses as $status) {
            $this->assertSame($counts[$status] ?? 0, $report['summary'][$status], $status);
        }
    }

    #[Test]
    public function the_json_output_contains_nothing_but_json(): void
    {
        Artisan::call('beacon:check', ['--json' => true]);
        $output = trim(Artisan::output());

        $this->assertStringStartsWith('{', $output);
        $this->assertStringEndsWith('}', $output);
        $this->assertStringNotContainsString("\e[", $output);
        $this->assertIsArray(json_decode($output, true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function enabling_mcp_without_a_token_is_a_configuration_problem(): void
    {
        [$exitCode, $report] = $this->check(['beacon.mcp.enabled' => true]);

        $this->assertSame(DiagnosticReport::EXIT_MISCONFIGURED, $exitCode);
        $this->assertSame('misconfigured', $report['status']);
        $this->assertSame('misconfigured', $this->statusOf($report, 'mcp'));
        $this->assertSame('misconfigured', $this->statusOf($report, 'auth'));
    }

    #[Test]
    public function a_short_token_is_misconfigured_and_never_printed(): void
    {
        [$exitCode, $report, $output] = $this->check([
            'beacon.mcp.enabled' => true,
            'beacon.auth.token' => 'too-short-secret',
        ]);

        $this->assertSame(DiagnosticReport::EXIT_MISCONFIGURED, $exitCode);
        $this->assertSame('misconfigured', $this->statusOf($report, 'auth'));
        $this->assertStringNotContainsString('too-short-secret', $output);
    }

    #[Test]
    public function a_valid_token_is_ready_and_never_printed(): void
    {
        $config = ['beacon.auth.token' => self::TOKEN];

        [$exitCode, $report, $json] = $this->check($config);
        [, $human] = $this->human($config);

        $this->assertSame(DiagnosticReport::EXIT_OK, $exitCode);
        $this->assertSame('pass', $this->statusOf($report, 'auth'));
        $this->assertStringNotContainsString(self::TOKEN, $json);
        $this->assertStringNotContainsString(self::TOKEN, $human);
    }

    #[Test]
    public function token_rotation_is_reported_without_printing_either_token(): void
    {
        [$exitCode, $report, $output] = $this->check([
            'beacon.auth.token' => self::TOKEN,
            'beacon.auth.previous_token' => self::PREVIOUS_TOKEN,
        ]);

        $this->assertSame(DiagnosticReport::EXIT_OK, $exitCode);
        $this->assertSame('warn', $report['status']);
        $this->assertSame('warn', $this->statusOf($report, 'auth'));
        $this->assertStringNotContainsString(self::TOKEN, $output);
        $this->assertStringNotContainsString(self::PREVIOUS_TOKEN, $output);
    }

    #[Test]
    public function a_short_previous_token_is_misconfigured(): void
    {
        [$exitCode, $report, $output] = $this->check([
            'beacon.auth.token' => self::TOKEN,
            'beacon.auth.previous_token' => 'old-short-secret',
        ]);

        $this->assertSame(DiagnosticReport::EXIT_MISCONFIGURED, $exitCode);
        $this->assertSame('misconfigured', $this->statusOf($report, 'auth'));
        $this->assertStringNotContainsString('old-short-secret', $output);
    }

    #[Test]
    public function invalid_configuration_values_are_reported_by_key_only(): void
    {
        [$exitCode, $report, $output] = $this->check([
            'beacon.mcp.enabled' => 'sometimes',
            'beacon.security.allowed_capabilities' => 42,
            'beacon.auth.token' => ['not-a-string-secret'],
            'beacon.mcp.http.path' => 'not a path!',
        ]);

        $this->assertSame(DiagnosticReport::EXIT_MISCONFIGURED, $exitCode);
        $this->assertSame('misconfigured', $this->statusOf($report, 'config'));

        $message = $this->findCheck($report, 'config')['message'];
        $this->assertIsString($message);
        $this->assertStringContainsString('mcp.enabled', $message);
        $this->assertStringContainsString('security.allowed_capabilities', $message);
        $this->assertStringContainsString('auth.token', $message);
        $this->assertStringContainsString('mcp.http.path', $message);
        $this->assertStringNotContainsString('sometimes', $output);
        $this->assertStringNotContainsString('not-a-string-secret', $output);
    }

    #[Test]
    public function a_missing_configuration_is_misconfigured(): void
    {
        [$exitCode, $report] = $this->check(['beacon' => 'broken']);

        $this->assertSame(DiagnosticReport::EXIT_MISCONFIGURED, $exitCode);
        $this->assertSame('misconfigured', $this->statusOf($report, 'config'));
    }

    #[Test]
    public function a_disabled_beacon_is_reported_as_disabled(): void
    {
        [$exitCode, $report] = $this->check(['beacon.enabled' => false, 'beacon.mcp.enabled' => true]);

        $this->assertSame(DiagnosticReport::EXIT_OK, $exitCode);
        $this->assertSame('disabled', $this->statusOf($report, 'mcp'));
        $this->assertSame('disabled', $this->statusOf($report, 'auth'));
    }

    #[Test]
    public function capability_allowlist_problems_are_warnings(): void
    {
        [, $unknown] = $this->check(['beacon.security.allowed_capabilities' => ['routes', 'shell']]);
        [, $wildcard] = $this->check(['beacon.security.allowed_capabilities' => ['*']]);
        [, $allowed] = $this->check(['beacon.security.allowed_capabilities' => ['routes']]);

        $this->assertSame('warn', $this->statusOf($unknown, 'security.capabilities'));
        $message = $this->findCheck($unknown, 'security.capabilities')['message'];
        $this->assertIsString($message);
        $this->assertStringContainsString('shell', $message);
        $this->assertSame('warn', $this->statusOf($wildcard, 'security.capabilities'));
        $this->assertSame('pass', $this->statusOf($allowed, 'security.capabilities'));
    }

    #[Test]
    public function debug_mode_in_production_is_a_warning(): void
    {
        $this->application()['env'] = 'production';
        $this->application()->forgetInstance(BeaconConfig::class);

        [$exitCode, $report] = $this->check(['app.debug' => true]);

        $this->assertSame(DiagnosticReport::EXIT_OK, $exitCode);
        $this->assertSame('warn', $this->statusOf($report, 'application'));
    }

    #[Test]
    public function a_failing_check_exits_with_two_without_leaking_details(): void
    {
        $this->application()->instance(Redactor::class, new ThrowingRedactor());

        [$exitCode, $report, $output] = $this->check();

        $this->assertSame(DiagnosticReport::EXIT_FAILED, $exitCode);
        $this->assertSame('fail', $report['status']);
        $this->assertSame(2, $report['exit_code']);
        $this->assertSame('fail', $this->statusOf($report, 'security.redaction'));
        $this->assertStringNotContainsString(ThrowingRedactor::MESSAGE, $output);
        $this->assertStringNotContainsString(ThrowingRedactor::class, $output);
    }

    #[Test]
    public function a_crash_still_produces_valid_json_and_exit_code_two(): void
    {
        $this->application()->bind(Diagnostics::class, static fn (): never => throw new \RuntimeException('db password is hunter2'));

        [$exitCode, $report, $output] = $this->check();
        [$humanExitCode, $human] = $this->human();

        $this->assertSame(DiagnosticReport::EXIT_FAILED, $exitCode);
        $this->assertSame('fail', $report['status']);
        $this->assertSame([], $report['checks']);
        $this->assertStringNotContainsString('hunter2', $output);
        $this->assertSame(DiagnosticReport::EXIT_FAILED, $humanExitCode);
        $this->assertStringContainsString('could not run', $human);
        $this->assertStringNotContainsString('hunter2', $human);
    }

    #[Test]
    public function the_human_output_labels_every_status(): void
    {
        [$exitCode, $output] = $this->human(['beacon.mcp.enabled' => true]);

        $this->assertSame(DiagnosticReport::EXIT_MISCONFIGURED, $exitCode);
        $this->assertStringContainsString('MISCONFIGURED', $output);
        $this->assertStringContainsString('DISABLED', $output);
        $this->assertStringContainsString('PASS', $output);
        $this->assertStringContainsString('Beacon is misconfigured', $output);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{int, array<array-key, mixed>, string}
     */
    private function check(array $config = []): array
    {
        $this->application()['config']->set($config);

        $exitCode = Artisan::call('beacon:check', ['--json' => true]);
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($report);

        return [$exitCode, $report, $output];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{int, string}
     */
    private function human(array $config = []): array
    {
        $this->application()['config']->set($config);

        $exitCode = Artisan::call('beacon:check');

        return [$exitCode, Artisan::output()];
    }

    /**
     * @param array<array-key, mixed> $report
     *
     * @return array<array-key, mixed>
     */
    private function findCheck(array $report, string $id): array
    {
        $this->assertIsArray($report['checks']);

        foreach ($report['checks'] as $check) {
            if (is_array($check) && $check['id'] === $id) {
                return $check;
            }
        }

        $this->fail("Check [{$id}] is missing.");
    }

    /**
     * @param array<array-key, mixed> $report
     */
    private function statusOf(array $report, string $id): mixed
    {
        return $this->findCheck($report, $id)['status'];
    }

    /**
     * @param array<array-key, mixed> $report
     */
    private function messageOf(array $report, string $id): mixed
    {
        return $this->findCheck($report, $id)['message'];
    }
}
