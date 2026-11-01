<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Console;

use Beacon\Console\Commands\MigrationsCommand;
use Beacon\Core\InspectorRegistry;
use Beacon\Health\Clock;
use Beacon\Inspectors\MigrationInspector;
use Beacon\Migrations\MigrationHistory;
use Beacon\Migrations\MigrationScanner;
use Beacon\Tests\Fixtures\FailingMigrationInspector;
use Beacon\Tests\Fixtures\FrozenClock;
use Beacon\Tests\Fixtures\RecordingMigrationHistory;
use Beacon\Tests\Fixtures\RecordingMigrationScanner;
use Beacon\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

final class MigrationsCommandTest extends TestCase
{
    #[Test]
    public function it_is_denied_until_the_migrations_capability_is_allowed(): void
    {
        config()->set('database.connections.sqlite.password', 'migration-password-not-in-output');

        $exitCode = Artisan::call('beacon:migrations');
        $output = Artisan::output();

        $this->assertSame(MigrationsCommand::EXIT_DENIED, $exitCode);
        $this->assertStringContainsString('not allowed', $output);
        $this->assertStringContainsString('BEACON_ALLOWED_CAPABILITIES=migrations', $output);
        $this->assertStringNotContainsString('migration-password-not-in-output', $output);
    }

    #[Test]
    public function json_output_matches_the_inspector(): void
    {
        $this->allow();
        $this->application()->instance(Clock::class, new FrozenClock('2026-10-25T13:33:00+00:00'));
        $this->application()->instance(MigrationHistory::class, new RecordingMigrationHistory());
        $this->application()->instance(MigrationScanner::class, new RecordingMigrationScanner());
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.password', 'migration-password-not-in-output');

        $exitCode = Artisan::call('beacon:migrations', ['--json' => true]);
        $output = Artisan::output();
        $decoded = json_decode($output, true);
        $expected = $this->application()->make(MigrationInspector::class)->inspect(['page' => 1, 'limit' => 25])->toArray();

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame($expected, $decoded);
        $this->assertSame('connected', data_get($decoded, 'connection.status'));
        $this->assertTrue(data_get($decoded, 'repository.empty'));
        $this->assertStringNotContainsString('migration-password-not-in-output', $output);
    }

    #[Test]
    public function an_unavailable_report_is_still_printed(): void
    {
        $this->allow();
        $history = new RecordingMigrationHistory();
        $history->fail = true;
        $this->application()->instance(MigrationHistory::class, $history);
        $this->application()->instance(MigrationScanner::class, new RecordingMigrationScanner());
        config()->set('database.default', 'mysql');

        $this->assertSame(0, Artisan::call('beacon:migrations'));
        $output = Artisan::output();

        $this->assertStringContainsString('unavailable', $output);
        $this->assertStringNotContainsString('migration-password-not-in-output', $output);
        $this->assertStringNotContainsString('db.internal', $output);
    }

    #[Test]
    public function an_invalid_filter_is_rejected_without_repeating_it(): void
    {
        $this->allow();

        $exitCode = Artisan::call('beacon:migrations', ['--path' => '../migration-path-not-in-output']);
        $output = Artisan::output();

        $this->assertSame(MigrationsCommand::EXIT_DENIED, $exitCode);
        $this->assertStringNotContainsString('migration-path-not-in-output', $output);
    }

    #[Test]
    public function a_failing_inspector_returns_a_generic_error(): void
    {
        $this->allow();
        $this->application()->make(InspectorRegistry::class)->register(MigrationInspector::NAME, FailingMigrationInspector::class);

        $exitCode = Artisan::call('beacon:migrations');
        $output = Artisan::output();

        $this->assertSame(MigrationsCommand::EXIT_FAILED, $exitCode);
        $this->assertStringContainsString('unavailable', $output);
        $this->assertStringNotContainsString('migration-inspector-secret', $output);
        $this->assertStringNotContainsString('SQLSTATE', $output);
    }

    private function allow(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['migrations']);
    }
}
