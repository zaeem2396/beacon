<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Console;

use Beacon\Console\Commands\MigrationRisksCommand;
use Beacon\Health\Clock;
use Beacon\Inspectors\MigrationRiskInspector;
use Beacon\Migrations\MigrationHistory;
use Beacon\Tests\Fixtures\FrozenClock;
use Beacon\Tests\Fixtures\RecordingMigrationHistory;
use Beacon\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

final class MigrationRisksCommandTest extends TestCase
{
    #[Test]
    public function it_is_denied_until_the_capability_is_allowed(): void
    {
        config()->set('database.connections.sqlite.password', 'migration-password-not-in-output');

        $exitCode = Artisan::call('beacon:migration-risks');
        $output = Artisan::output();

        $this->assertSame(MigrationRisksCommand::EXIT_DENIED, $exitCode);
        $this->assertStringContainsString('not allowed', $output);
        $this->assertStringContainsString('BEACON_ALLOWED_CAPABILITIES=migration_risk', $output);
        $this->assertStringNotContainsString('migration-password-not-in-output', $output);
    }

    #[Test]
    public function json_output_matches_the_inspector(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['migration_risk']);
        $this->application()->instance(Clock::class, new FrozenClock('2026-10-25T13:33:00+00:00'));
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.driver', 'mysql');
        config()->set('database.connections.mysql.password', 'migration-password-not-in-output');
        $this->application()->instance(MigrationHistory::class, new RecordingMigrationHistory());

        $exitCode = Artisan::call('beacon:migration-risks', ['--json' => true, '--severity' => 'high']);
        $output = Artisan::output();
        $decoded = json_decode($output, true);
        $expected = $this->application()->make(MigrationRiskInspector::class)->inspect([
            'page' => 1,
            'limit' => 100,
            'include_executed' => false,
            'enrich' => false,
            'severity' => 'high',
        ])->toArray();

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame($expected, $decoded);
        $this->assertFalse(data_get($decoded, 'scope.enriched'));
        $this->assertStringNotContainsString('migration-password-not-in-output', $output);
    }

    #[Test]
    public function an_invalid_filter_is_rejected_without_repeating_it(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['migration_risk']);

        $exitCode = Artisan::call('beacon:migration-risks', ['--path' => '../risk-path-not-in-output']);
        $output = Artisan::output();

        $this->assertSame(MigrationRisksCommand::EXIT_DENIED, $exitCode);
        $this->assertStringNotContainsString('risk-path-not-in-output', $output);
    }
}
