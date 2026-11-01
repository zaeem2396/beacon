<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Core\Beacon;
use Beacon\Data\SchemaInspection;
use Beacon\Database\ConnectionStatus;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Exceptions\InvalidSchemaQueryException;
use Beacon\Health\Clock;
use Beacon\Inspectors\SchemaInspector;
use Beacon\Schema\SchemaReader;
use Beacon\Security\Principal;
use Beacon\Tests\Fixtures\FrozenClock;
use Beacon\Tests\Fixtures\RecordingSchemaReader;
use Beacon\Tests\TestCase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

final class SchemaInspectorTest extends TestCase
{
    #[Test]
    public function an_unlisted_connection_is_not_opened(): void
    {
        $reader = $this->reader();

        $inspection = $this->inspect(['connection' => 'reports']);

        $this->assertSame(ConnectionStatus::Unknown, $inspection->connection->status);
        $this->assertNull($inspection->connection->name);
        $this->assertSame(0, $reader->calls);
        $this->assertStringNotContainsString('reports', (string) json_encode($inspection->toArray()));
    }

    #[Test]
    public function a_configured_connection_can_be_inspected(): void
    {
        $reader = $this->reader();
        config()->set('beacon.schema.connections', ['reports']);
        config()->set('database.connections.reports', ['driver' => 'mysql', 'password' => 'schema-password-not-in-output']);

        $inspection = $this->inspect(['connection' => 'reports']);
        $encoded = (string) json_encode($inspection->toArray());

        $this->assertSame(ConnectionStatus::Connected, $inspection->connection->status);
        $this->assertSame(['schema_version', 'connection', 'summary', 'pagination', 'objects', 'warnings'], array_keys($inspection->toArray()));
        $this->assertSame('reports', $inspection->connection->name);
        $this->assertSame('mysql', $inspection->connection->driver);
        $this->assertSame('appdb', $inspection->connection->database);
        $this->assertSame(1, $reader->calls);
        $this->assertStringNotContainsString('schema-password-not-in-output', $encoded);
    }

    #[Test]
    public function postgresql_is_identified_and_not_opened(): void
    {
        $reader = $this->reader();
        config()->set('database.default', 'pgsql');
        config()->set('database.connections.pgsql.driver', 'pgsql');
        config()->set('database.connections.pgsql.password', 'schema-password-not-in-output');

        $inspection = $this->inspect();

        $this->assertSame(ConnectionStatus::Unsupported, $inspection->connection->status);
        $this->assertSame('pgsql', $inspection->connection->driver);
        $this->assertSame(0, $reader->calls);
        $this->assertStringNotContainsString('schema-password-not-in-output', (string) json_encode($inspection->toArray()));
    }

    #[Test]
    public function sqlite_is_not_opened(): void
    {
        $reader = $this->reader();

        $inspection = $this->inspect();

        $this->assertSame(ConnectionStatus::Unsupported, $inspection->connection->status);
        $this->assertSame('sqlite', $inspection->connection->driver);
        $this->assertSame(0, $reader->calls);
    }

    #[Test]
    public function an_invalid_timeout_does_not_open_the_connection(): void
    {
        $reader = $this->reader();
        config()->set('beacon.schema.timeout_seconds', '2.5');
        config()->set('database.default', 'mysql');

        $inspection = $this->inspect();

        $this->assertSame(ConnectionStatus::Unknown, $inspection->connection->status);
        $this->assertSame(0, $reader->calls);
    }

    #[Test]
    public function a_read_failure_drops_the_driver_error(): void
    {
        $reader = $this->reader();
        $reader->fail = true;
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.password', 'schema-password-not-in-output');
        $logged = '';
        Log::listen(function (MessageLogged $message) use (&$logged): void {
            $logged .= $message->message . ' ' . json_encode($message->context);
        });

        $inspection = $this->inspect();
        $encoded = (string) json_encode($inspection->toArray());

        $this->assertSame(ConnectionStatus::Unavailable, $inspection->connection->status);
        $this->assertSame([], $inspection->objects);

        foreach (['schema-password-not-in-output', 'db.internal', 'SQLSTATE'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
            $this->assertStringNotContainsString($secret, $logged);
        }
    }

    #[Test]
    public function arguments_are_rejected_without_repeating_them(): void
    {
        $reader = $this->reader();

        try {
            $this->inspect(['table' => "orders'; drop table orders"]);
            $this->fail('The filter was accepted.');
        } catch (InvalidSchemaQueryException $exception) {
            $this->assertStringNotContainsString('drop table', $exception->getMessage());
        }

        $this->assertSame(0, $reader->calls);
    }

    #[Test]
    public function the_schema_capability_is_required(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['database']);

        $this->expectException(AuthorizationException::class);

        $this->application()->make(Beacon::class)->inspect(Principal::local(), SchemaInspector::NAME);
    }

    #[Test]
    public function a_disabled_inspector_is_not_found(): void
    {
        config()->set('beacon.inspectors.schema.enabled', false);
        config()->set('beacon.security.allowed_capabilities', ['schema']);

        $this->expectException(InspectorNotFoundException::class);

        $this->application()->make(Beacon::class)->inspect(Principal::local(), SchemaInspector::NAME);
    }

    private function reader(): RecordingSchemaReader
    {
        $reader = new RecordingSchemaReader();
        $this->application()->instance(SchemaReader::class, $reader);
        $this->application()->instance(Clock::class, new FrozenClock('2026-10-25T13:33:00+00:00'));

        return $reader;
    }

    /**
     * @param array<string, bool|float|int|string|null> $parameters
     */
    private function inspect(array $parameters = []): SchemaInspection
    {
        config()->set('beacon.security.allowed_capabilities', ['schema']);
        $inspection = $this->application()->make(SchemaInspector::class)->inspect($parameters);
        $this->assertInstanceOf(SchemaInspection::class, $inspection);

        return $inspection;
    }
}
