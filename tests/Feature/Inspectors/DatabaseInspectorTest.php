<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Core\Beacon;
use Beacon\Data\DatabaseStatus;
use Beacon\Database\ConnectionStatus;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Inspectors\DatabaseInspector;
use Beacon\Security\Principal;
use Beacon\Tests\TestCase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;

final class DatabaseInspectorTest extends TestCase
{
    private const PASSWORD = 'database-password-not-in-output';

    #[Test]
    public function sqlite_answers_a_read_only_probe_without_leaking_the_configuration(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is not loaded.');
        }

        Http::fake();
        Mail::fake();
        Queue::fake();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.password', self::PASSWORD);
        config()->set('database.connections.sqlite.url', 'sqlite:///tmp/database-url-not-in-output.sqlite');
        $options = config('database.connections.sqlite.options');

        $status = $this->inspect();
        $encoded = (string) json_encode($status->toArray());

        $this->assertSame(ConnectionStatus::Connected, $status->status);
        $this->assertSame('sqlite', $status->connection);
        $this->assertSame('sqlite', $status->driver);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) $status->version);
        $this->assertIsInt($status->latencyMs);
        $this->assertGreaterThanOrEqual(0, $status->latencyMs);
        $this->assertLessThanOrEqual(120000, $status->latencyMs);
        $this->assertStringNotContainsString(self::PASSWORD, $encoded);
        $this->assertStringNotContainsString(':memory:', $encoded);
        $this->assertStringNotContainsString('database-url-not-in-output', $encoded);
        $this->assertStringNotContainsString('/tmp/', $encoded);
        $this->assertSame($options, config('database.connections.sqlite.options'));
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_refused_mysql_connection_is_unavailable_and_does_not_log_the_driver_error(): void
    {
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql is not loaded.');
        }

        $logged = '';
        Log::listen(function (MessageLogged $message) use (&$logged): void {
            $logged .= $message->message . ' ' . json_encode($message->context);
        });
        $default = config('database.default');

        try {
            config()->set('database.default', 'mysql');
            config()->set('database.connections.mysql.host', '127.0.0.1');
            config()->set('database.connections.mysql.port', 1);
            config()->set('database.connections.mysql.database', 'secret-database-not-in-output');
            config()->set('database.connections.mysql.username', 'secret-user-not-in-output');
            config()->set('database.connections.mysql.password', self::PASSWORD);
            config()->set('database.connections.mysql.url', 'mysql://beacon:' . self::PASSWORD . '@db.internal/app');
            config()->set('beacon.database.timeout_seconds', 1);

            $status = $this->inspect();
            $encoded = (string) json_encode($status->toArray());

            $this->assertSame(ConnectionStatus::Unavailable, $status->status);
            $this->assertSame('mysql', $status->connection);
            $this->assertSame('mysql', $status->driver);
            $this->assertNull($status->version);
            $this->assertNull($status->latencyMs);

            foreach ([self::PASSWORD, 'db.internal', 'SQLSTATE', 'secret-database-not-in-output', 'secret-user-not-in-output', '127.0.0.1'] as $secret) {
                $this->assertStringNotContainsString($secret, $encoded);
                $this->assertStringNotContainsString($secret, $logged);
            }
        } finally {
            config()->set('database.default', $default);
        }
    }

    #[Test]
    public function the_database_capability_is_required(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['application']);

        $this->expectException(AuthorizationException::class);

        $this->application()->make(Beacon::class)->inspect(Principal::local(), DatabaseInspector::NAME);
    }

    #[Test]
    public function a_disabled_inspector_is_not_found(): void
    {
        config()->set('beacon.inspectors.database.enabled', false);

        $this->expectException(InspectorNotFoundException::class);

        $this->application()->make(Beacon::class)->inspect(Principal::local(), DatabaseInspector::NAME);
    }

    private function inspect(): DatabaseStatus
    {
        config()->set('beacon.security.allowed_capabilities', ['database']);
        $status = $this->application()->make(DatabaseInspector::class)->inspect();
        $this->assertInstanceOf(DatabaseStatus::class, $status);

        return $status;
    }
}
