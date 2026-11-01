<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Console;

use Beacon\Console\Commands\SchemaCommand;
use Beacon\Core\InspectorRegistry;
use Beacon\Health\Clock;
use Beacon\Inspectors\SchemaInspector;
use Beacon\Schema\SchemaReader;
use Beacon\Tests\Fixtures\FailingSchemaInspector;
use Beacon\Tests\Fixtures\FrozenClock;
use Beacon\Tests\Fixtures\RecordingSchemaReader;
use Beacon\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

final class SchemaCommandTest extends TestCase
{
    #[Test]
    public function it_is_denied_until_the_schema_capability_is_allowed(): void
    {
        config()->set('database.connections.sqlite.password', 'schema-password-not-in-output');

        $exitCode = Artisan::call('beacon:schema');
        $output = Artisan::output();

        $this->assertSame(SchemaCommand::EXIT_DENIED, $exitCode);
        $this->assertStringContainsString('not allowed', $output);
        $this->assertStringContainsString('BEACON_ALLOWED_CAPABILITIES=schema', $output);
        $this->assertStringNotContainsString('schema-password-not-in-output', $output);
    }

    #[Test]
    public function json_output_matches_the_inspector(): void
    {
        $this->allow();
        $this->application()->instance(Clock::class, new FrozenClock('2026-10-25T13:33:00+00:00'));
        $this->application()->instance(SchemaReader::class, new RecordingSchemaReader());
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.password', 'schema-password-not-in-output');

        $exitCode = Artisan::call('beacon:schema', ['--json' => true]);
        $output = Artisan::output();
        $decoded = json_decode($output, true);
        $expected = $this->application()->make(SchemaInspector::class)->inspect(['page' => 1, 'limit' => 25])->toArray();

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame($expected, $decoded);
        $this->assertSame('connected', data_get($decoded, 'connection.status'));
        $this->assertStringNotContainsString('schema-password-not-in-output', $output);
        $this->assertDoesNotMatchRegularExpression('/\e/', $output);
    }

    #[Test]
    public function an_unavailable_report_is_still_printed(): void
    {
        $this->allow();
        $reader = new RecordingSchemaReader();
        $reader->fail = true;
        $this->application()->instance(SchemaReader::class, $reader);
        config()->set('database.default', 'mysql');

        $this->assertSame(0, Artisan::call('beacon:schema'));
        $output = Artisan::output();

        $this->assertStringContainsString('unavailable', $output);
        $this->assertStringNotContainsString('schema-password-not-in-output', $output);
        $this->assertStringNotContainsString('db.internal', $output);
    }

    #[Test]
    public function an_invalid_filter_is_rejected_without_repeating_it(): void
    {
        $this->allow();

        $exitCode = Artisan::call('beacon:schema', ['--table' => "orders'; drop table orders"]);
        $output = Artisan::output();

        $this->assertSame(SchemaCommand::EXIT_DENIED, $exitCode);
        $this->assertStringNotContainsString('drop table', $output);
    }

    #[Test]
    public function an_inspection_failure_returns_a_generic_error_without_internal_details(): void
    {
        $this->allow();
        $this->application()->make(InspectorRegistry::class)->register(SchemaInspector::NAME, FailingSchemaInspector::class);

        $exitCode = Artisan::call('beacon:schema', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(SchemaCommand::EXIT_FAILED, $exitCode);
        $this->assertStringContainsString('unavailable', $output);

        foreach (FailingSchemaInspector::DETAILS as $detail) {
            $this->assertStringNotContainsString($detail, $output);
        }
    }

    private function allow(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['schema']);
    }
}
