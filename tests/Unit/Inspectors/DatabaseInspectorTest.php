<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Inspectors;

use Beacon\Core\BeaconConfig;
use Beacon\Database\ConnectionStatus;
use Beacon\Exceptions\InvalidDatabaseQueryException;
use Beacon\Inspectors\DatabaseInspector;
use Beacon\Tests\Fixtures\RecordingStatusProbe;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DatabaseInspectorTest extends TestCase
{
    #[Test]
    public function a_mysql_probe_keeps_a_short_version_and_drops_the_secret(): void
    {
        $probe = new RecordingStatusProbe();
        $repository = $this->repository([
            'beacon' => ['database' => ['timeout_seconds' => '3']],
            'database' => [
                'default' => 'mysql',
                'connections' => [
                    'mysql' => [
                        'driver' => 'mysql',
                        'password' => 'database-password-not-in-output',
                        'host' => 'db-host-not-in-output.example',
                        'database' => 'secret-database-not-in-output',
                    ],
                ],
            ],
        ]);

        $status = (new DatabaseInspector($this->config($repository), $repository, $probe))->inspect();
        $encoded = (string) json_encode($status->toArray());

        $this->assertSame(ConnectionStatus::Connected, $status->status);
        $this->assertSame('mysql', $status->connection);
        $this->assertSame('mysql', $status->driver);
        $this->assertSame('8.0.36', $status->version);
        $this->assertSame(4, $status->latencyMs);
        $this->assertSame(1, $probe->calls);
        $this->assertSame(3, $probe->timeout);
        $this->assertStringNotContainsString('database-password-not-in-output', $encoded);
        $this->assertStringNotContainsString('db-host-not-in-output.example', $encoded);
        $this->assertStringNotContainsString('secret-database-not-in-output', $encoded);
        $this->assertStringNotContainsString('ubuntu', $encoded);
    }

    #[Test]
    public function an_unreadable_version_stays_connected(): void
    {
        $probe = new RecordingStatusProbe();
        $probe->version = 'mysql://beacon:database-password-not-in-output@db.internal/app';
        $repository = $this->repository([
            'database' => [
                'default' => 'mysql',
                'connections' => ['mysql' => ['driver' => 'mysql']],
            ],
        ]);

        $status = (new DatabaseInspector($this->config($repository), $repository, $probe))->inspect();

        $this->assertSame(ConnectionStatus::Connected, $status->status);
        $this->assertNull($status->version);
        $this->assertSame(4, $status->latencyMs);
        $this->assertSame('The default database connection answered, and the server version was not readable.', $status->summary);
        $this->assertStringNotContainsString('database-password-not-in-output', (string) json_encode($status->toArray()));
        $this->assertStringNotContainsString('db.internal', (string) json_encode($status->toArray()));
    }

    #[Test]
    public function a_probe_failure_is_unavailable_and_drops_the_driver_error(): void
    {
        $probe = new RecordingStatusProbe();
        $probe->fail = true;
        $repository = $this->repository([
            'beacon' => ['database' => ['timeout_seconds' => 1]],
            'database' => [
                'default' => 'mysql',
                'connections' => ['mysql' => ['driver' => 'mysql', 'password' => 'database-password-not-in-output']],
            ],
        ]);

        $status = (new DatabaseInspector($this->config($repository), $repository, $probe))->inspect();
        $encoded = (string) json_encode($status->toArray());

        $this->assertSame(ConnectionStatus::Unavailable, $status->status);
        $this->assertNull($status->version);
        $this->assertNull($status->latencyMs);
        $this->assertStringNotContainsString('database-password-not-in-output', $encoded);
        $this->assertStringNotContainsString('db.internal', $encoded);
    }

    #[Test]
    public function postgresql_is_identified_and_not_opened(): void
    {
        $probe = new RecordingStatusProbe();
        $repository = $this->repository([
            'database' => [
                'default' => 'pgsql',
                'connections' => ['pgsql' => ['driver' => 'pgsql', 'password' => 'database-password-not-in-output']],
            ],
        ]);

        $status = (new DatabaseInspector($this->config($repository), $repository, $probe))->inspect();

        $this->assertSame(ConnectionStatus::Unsupported, $status->status);
        $this->assertSame('pgsql', $status->driver);
        $this->assertSame(0, $probe->calls);
        $this->assertStringNotContainsString('database-password-not-in-output', (string) json_encode($status->toArray()));
    }

    #[Test]
    public function an_invalid_timeout_does_not_open_the_connection(): void
    {
        $probe = new RecordingStatusProbe();
        $repository = $this->repository([
            'beacon' => ['database' => ['timeout_seconds' => 100]],
            'database' => ['default' => 'mysql', 'connections' => ['mysql' => ['driver' => 'mysql']]],
        ]);

        $status = (new DatabaseInspector($this->config($repository), $repository, $probe))->inspect();

        $this->assertSame(ConnectionStatus::Unknown, $status->status);
        $this->assertSame(0, $probe->calls);
    }

    #[Test]
    public function a_dotted_connection_name_is_not_followed(): void
    {
        $probe = new RecordingStatusProbe();
        $repository = $this->repository([
            'database' => ['default' => 'reports.primary', 'connections' => []],
        ]);

        $status = (new DatabaseInspector($this->config($repository), $repository, $probe))->inspect();

        $this->assertSame(ConnectionStatus::Unknown, $status->status);
        $this->assertNull($status->connection);
        $this->assertSame(0, $probe->calls);
        $this->assertStringNotContainsString('reports.primary', (string) json_encode($status->toArray()));
    }

    #[Test]
    public function a_missing_sqlite_file_is_not_created(): void
    {
        $path = sys_get_temp_dir() . '/beacon-database-status-missing.sqlite';
        @unlink($path);
        $probe = new RecordingStatusProbe();
        $repository = $this->repository([
            'database' => [
                'default' => 'sqlite',
                'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => $path]],
            ],
        ]);

        $status = (new DatabaseInspector($this->config($repository), $repository, $probe))->inspect();

        $this->assertSame(ConnectionStatus::Unknown, $status->status);
        $this->assertSame(0, $probe->calls);
        $this->assertFileDoesNotExist($path);
        $this->assertStringNotContainsString('beacon-database-status-missing.sqlite', (string) json_encode($status->toArray()));
    }

    #[Test]
    public function an_unreadable_driver_is_not_echoed(): void
    {
        $probe = new RecordingStatusProbe();
        $repository = $this->repository([
            'database' => [
                'default' => 'mysql',
                'connections' => ['mysql' => ['driver' => 'driver-secret-not-in-output']],
            ],
        ]);

        $status = (new DatabaseInspector($this->config($repository), $repository, $probe))->inspect();

        $this->assertSame(ConnectionStatus::Unknown, $status->status);
        $this->assertNull($status->driver);
        $this->assertSame(0, $probe->calls);
        $this->assertStringNotContainsString('driver-secret-not-in-output', (string) json_encode($status->toArray()));
    }

    #[Test]
    public function arguments_are_rejected_without_repeating_them(): void
    {
        $probe = new RecordingStatusProbe();
        $inspector = new DatabaseInspector($this->config(new Repository()), new Repository(), $probe);

        try {
            $inspector->inspect(['connection' => 'probe-secret-not-in-output']);
            $this->fail('Arguments were accepted.');
        } catch (InvalidDatabaseQueryException $exception) {
            $this->assertSame('Database status does not accept arguments.', $exception->getMessage());
            $this->assertStringNotContainsString('probe-secret-not-in-output', $exception->getMessage());
        }

        $this->assertSame(0, $probe->calls);
    }

    /**
     * @param array<string, mixed> $items
     */
    private function repository(array $items): Repository
    {
        return new Repository($items);
    }

    private function config(Repository $repository): BeaconConfig
    {
        return new BeaconConfig($repository, 'testing');
    }
}
