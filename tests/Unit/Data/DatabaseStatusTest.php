<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Data;

use Beacon\Data\DatabaseStatus;
use Beacon\Database\ConnectionStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DatabaseStatusTest extends TestCase
{
    #[Test]
    public function a_status_serializes_in_declaration_order(): void
    {
        $status = $this->connected();

        $this->assertSame([
            'schema_version',
            'connection',
            'driver',
            'status',
            'version',
            'latency_ms',
            'summary',
        ], array_keys($status->toArray()));
        $this->assertSame(1, $status->schemaVersion);
        $this->assertSame('connected', $status->toArray()['status']);
        $this->assertSame('8.0.36', $status->version);
    }

    #[Test]
    public function a_connected_status_requires_latency(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DatabaseStatus('mysql', 'mysql', ConnectionStatus::Connected, '8.0.36', null, 'The default database connection answered a read-only status probe.');
    }

    #[Test]
    public function an_unavailable_status_does_not_carry_a_version(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DatabaseStatus('mysql', 'mysql', ConnectionStatus::Unavailable, '8.0.36', null, 'The default database connection did not answer a read-only status probe.');
    }

    #[Test]
    public function a_version_cannot_carry_a_path_or_address(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DatabaseStatus('mysql', 'mysql', ConnectionStatus::Connected, 'mysql://db.internal/app', 1, 'The default database connection answered a read-only status probe.');
    }

    #[Test]
    public function an_unsupported_status_names_a_driver_this_release_does_not_open(): void
    {
        $status = new DatabaseStatus('pgsql', 'pgsql', ConnectionStatus::Unsupported, null, null, 'This database driver is recognised, but this release does not open it.');

        $this->assertNull($status->version);
        $this->assertNull($status->latencyMs);

        $this->expectException(\InvalidArgumentException::class);

        new DatabaseStatus('mysql', 'mysql', ConnectionStatus::Unsupported, null, null, 'This database driver is recognised, but this release does not open it.');
    }

    private function connected(): DatabaseStatus
    {
        return new DatabaseStatus(
            'mysql',
            'mysql',
            ConnectionStatus::Connected,
            '8.0.36',
            12,
            'The default database connection answered a read-only status probe.',
        );
    }
}
