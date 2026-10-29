<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Console;

use Beacon\Console\Commands\DatabaseCommand;
use Beacon\Core\InspectorRegistry;
use Beacon\Database\StatusProbe;
use Beacon\Inspectors\DatabaseInspector;
use Beacon\Tests\Fixtures\FailingDatabaseInspector;
use Beacon\Tests\Fixtures\RecordingStatusProbe;
use Beacon\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

final class DatabaseCommandTest extends TestCase
{
    #[Test]
    public function it_is_denied_until_the_database_capability_is_allowed(): void
    {
        config()->set('database.connections.sqlite.password', 'database-password-not-in-output');

        $exitCode = Artisan::call('beacon:database');
        $output = Artisan::output();

        $this->assertSame(DatabaseCommand::EXIT_DENIED, $exitCode);
        $this->assertStringContainsString('not allowed', $output);
        $this->assertStringContainsString('BEACON_ALLOWED_CAPABILITIES=database', $output);
        $this->assertStringNotContainsString('database-password-not-in-output', $output);
    }

    #[Test]
    public function json_output_matches_the_inspector(): void
    {
        $this->allow();
        $probe = new RecordingStatusProbe();
        $this->application()->instance(StatusProbe::class, $probe);
        config()->set('database.connections.sqlite.password', 'database-password-not-in-output');
        config()->set('database.connections.sqlite.database', ':memory:');

        $exitCode = Artisan::call('beacon:database', ['--json' => true]);
        $output = Artisan::output();
        $decoded = json_decode($output, true);
        $expected = $this->application()->make(DatabaseInspector::class)->inspect()->toArray();

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame($expected, $decoded);
        $this->assertSame('connected', $decoded['status']);
        $this->assertSame('8.0.36', $decoded['version']);
        $this->assertStringNotContainsString('database-password-not-in-output', $output);
        $this->assertStringNotContainsString(':memory:', $output);
        $this->assertDoesNotMatchRegularExpression('/\e/', $output);
    }

    #[Test]
    public function an_unavailable_report_is_still_printed(): void
    {
        $this->allow();
        $probe = new RecordingStatusProbe();
        $probe->fail = true;
        $this->application()->instance(StatusProbe::class, $probe);

        $this->assertSame(0, Artisan::call('beacon:database'));
        $output = Artisan::output();

        $this->assertStringContainsString('unavailable', $output);
        $this->assertStringNotContainsString('database-password-not-in-output', $output);
        $this->assertStringNotContainsString('db.internal', $output);
    }

    #[Test]
    public function a_disabled_beacon_or_inspector_is_rejected(): void
    {
        $this->allow();
        config()->set('beacon.enabled', false);

        $this->assertSame(DatabaseCommand::EXIT_DENIED, Artisan::call('beacon:database', ['--json' => true]));
        $denied = json_decode(Artisan::output(), true);
        $this->assertIsArray($denied);
        $this->assertSame('not_allowed', $denied['error']);

        config()->set('beacon.enabled', true);
        config()->set('beacon.inspectors.database.enabled', false);

        $exitCode = Artisan::call('beacon:database');

        $this->assertSame(DatabaseCommand::EXIT_DENIED, $exitCode);
        $this->assertStringContainsString('disabled', Artisan::output());
    }

    #[Test]
    public function an_inspection_failure_returns_a_generic_error_without_internal_details(): void
    {
        $this->allow();
        $this->application()->make(InspectorRegistry::class)->register(DatabaseInspector::NAME, FailingDatabaseInspector::class);

        foreach ([false, true] as $json) {
            $exitCode = Artisan::call('beacon:database', ['--json' => $json]);
            $output = Artisan::output();

            $this->assertSame(DatabaseCommand::EXIT_FAILED, $exitCode);
            $this->assertStringContainsString('unavailable', $output);

            foreach (FailingDatabaseInspector::DETAILS as $detail) {
                $this->assertStringNotContainsString($detail, $output);
            }

            $this->assertStringNotContainsString('FailingDatabaseInspector', $output);
        }
    }

    private function allow(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['database']);
    }
}
