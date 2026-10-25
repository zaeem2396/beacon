<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Console;

use Beacon\Console\Commands\HealthCommand;
use Beacon\Core\InspectorRegistry;
use Beacon\Health\Clock;
use Beacon\Inspectors\HealthInspector;
use Beacon\Tests\Fixtures\FailingHealthInspector;
use Beacon\Tests\Fixtures\FrozenClock;
use Beacon\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

final class HealthCommandTest extends TestCase
{
    #[Test]
    public function it_is_denied_until_the_health_capability_is_allowed(): void
    {
        config()->set('app.name', 'Checkout');

        $exitCode = Artisan::call('beacon:health');
        $output = Artisan::output();

        $this->assertSame(HealthCommand::EXIT_DENIED, $exitCode);
        $this->assertStringContainsString('not allowed', $output);
        $this->assertStringContainsString('BEACON_ALLOWED_CAPABILITIES=health', $output);
        $this->assertStringNotContainsString('Checkout', $output);
    }

    #[Test]
    public function it_prints_the_health_report(): void
    {
        $this->allow();

        $this->assertSame(0, Artisan::call('beacon:health'));
        $output = Artisan::output();

        $this->assertStringContainsString('healthy', $output);
        $this->assertStringContainsString('skipped', $output);
        $this->assertStringContainsString('application', $output);
        $this->assertStringContainsString('database', $output);
        $this->assertStringContainsString('cache', $output);
        $this->assertStringContainsString('queue', $output);
    }

    #[Test]
    public function json_output_matches_the_inspector_schema(): void
    {
        $this->allow();
        $this->application()->instance(Clock::class, new FrozenClock('2026-10-25T13:33:00+00:00'));
        config()->set('database.connections.sqlite.password', 'database-password-not-in-output');

        $exitCode = Artisan::call('beacon:health', ['--json' => true]);
        $output = Artisan::output();
        $decoded = json_decode($output, true);
        $expected = $this->application()->make(HealthInspector::class)->inspect()->toArray();

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame($this->withoutDurations($expected), $this->withoutDurations($decoded));
        $this->assertSame('2026-10-25T13:33:00+00:00', $decoded['assessed_at']);
        $this->assertStringNotContainsString('database-password-not-in-output', $output);
        $this->assertStringNotContainsString($this->application()->basePath(), $output);
        $this->assertDoesNotMatchRegularExpression('/\e/', $output);
    }

    #[Test]
    public function a_critical_report_is_still_printed(): void
    {
        $this->allow();
        $this->application()->instance(Clock::class, new FrozenClock('2026-10-25T13:33:00+00:00'));
        config()->set('beacon.health.cache.enabled', true);
        $this->application()->instance(\Beacon\Health\CacheProbe::class, new \Beacon\Tests\Fixtures\ThrowingCacheProbe());

        $this->assertSame(0, Artisan::call('beacon:health', ['--json' => true]));
        $decoded = json_decode(Artisan::output(), true);

        $this->assertIsArray($decoded);
        $this->assertSame('critical', $decoded['status']);
        $this->assertStringNotContainsString('cache-secret-not-in-output', Artisan::output());
    }

    #[Test]
    public function a_disabled_beacon_or_inspector_is_rejected(): void
    {
        $this->allow();
        config()->set('beacon.enabled', false);

        $this->assertSame(HealthCommand::EXIT_DENIED, Artisan::call('beacon:health', ['--json' => true]));
        $denied = json_decode(Artisan::output(), true);
        $this->assertIsArray($denied);
        $this->assertSame('not_allowed', $denied['error']);

        config()->set('beacon.enabled', true);
        config()->set('beacon.inspectors.health.enabled', false);

        $exitCode = Artisan::call('beacon:health');

        $this->assertSame(HealthCommand::EXIT_DENIED, $exitCode);
        $this->assertStringContainsString('disabled', Artisan::output());
    }

    #[Test]
    public function an_inspection_failure_returns_a_generic_error_without_internal_details(): void
    {
        $this->allow();
        $this->application()->make(InspectorRegistry::class)->register(HealthInspector::NAME, FailingHealthInspector::class);

        foreach ([false, true] as $json) {
            $exitCode = Artisan::call('beacon:health', ['--json' => $json]);
            $output = Artisan::output();

            $this->assertSame(HealthCommand::EXIT_FAILED, $exitCode);
            $this->assertStringContainsString('unavailable', $output);

            foreach (FailingHealthInspector::DETAILS as $detail) {
                $this->assertStringNotContainsString($detail, $output);
            }

            $this->assertStringNotContainsString('health-inspector-secret', $output);
            $this->assertStringNotContainsString('FailingHealthInspector', $output);
        }
    }

    private function allow(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['health']);
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    private function withoutDurations(array $report): array
    {
        $checks = $report['checks'] ?? null;
        $this->assertIsArray($checks);

        foreach ($checks as $index => $check) {
            $this->assertIsArray($check);
            $this->assertIsInt($check['duration_ms']);
            $this->assertGreaterThanOrEqual(0, $check['duration_ms']);
            $this->assertLessThanOrEqual(120000, $check['duration_ms']);
            $check['duration_ms'] = 0;
            $checks[$index] = $check;
        }

        $report['checks'] = $checks;

        return $report;
    }
}
