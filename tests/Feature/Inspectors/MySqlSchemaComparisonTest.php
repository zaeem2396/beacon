<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Database\ConnectionStatus;
use Beacon\Inspectors\SchemaComparisonInspector;
use Beacon\Inspectors\SchemaSnapshotInspector;
use Beacon\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Snapshot capture and comparison against MySQL or MariaDB.
 *
 * The sentinel row must stay in the table and must not appear in the snapshot.
 */
#[Group('mysql')]
final class MySqlSchemaComparisonTest extends TestCase
{
    #[Test]
    public function mysql_snapshot_does_not_read_or_change_rows(): void
    {
        $this->assertDriver('mysql', 'BEACON_MYSQL');
    }

    #[Test]
    public function mariadb_snapshot_does_not_read_or_change_rows(): void
    {
        $this->assertDriver('mariadb', 'BEACON_MARIADB');
    }

    private function assertDriver(string $driver, string $prefix): void
    {
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql is not loaded.');
        }

        $host = $this->env($prefix . '_HOST');

        if ($host === null) {
            $this->markTestSkipped($prefix . '_HOST is not set.');
        }

        $username = $this->env($prefix . '_USERNAME') ?? 'root';
        $password = $this->env($prefix . '_PASSWORD') ?? '';
        $port = $this->env($prefix . '_PORT') ?? '3306';
        $pdo = new \PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, (int) $port),
            $username,
            $password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec('drop database if exists beacon_compare_it');
        $pdo->exec('create database beacon_compare_it');
        $pdo->exec('use beacon_compare_it');
        $pdo->exec('create table orders (id int unsigned not null primary key, legacy varchar(40) not null)');
        $pdo->exec("insert into orders (id, legacy) values (1, 'record-not-in-output')");

        try {
            config()->set('database.default', 'beacon_compare');
            config()->set('database.connections.beacon_compare', [
                'driver' => $driver,
                'host' => $host,
                'port' => $port,
                'database' => 'beacon_compare_it',
                'username' => $username,
                'password' => $password,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ]);
            config()->set('beacon.security.allowed_capabilities', ['schema_snapshot', 'schema_compare', 'schema']);
            config()->set('beacon.schema.timeout_seconds', 5);

            $before = $this->application()->make(SchemaSnapshotInspector::class)->inspect([]);
            $beforeJson = (string) json_encode($before->toArray());
            $pdo->exec('alter table orders add note varchar(20) null');
            $after = $this->application()->make(SchemaSnapshotInspector::class)->inspect([]);
            $comparison = $this->application()->make(SchemaComparisonInspector::class)->inspect([
                'baseline' => (string) json_encode(['snapshot' => $before->snapshot->toArray()]),
                'candidate' => (string) json_encode(['snapshot' => $after->snapshot->toArray()]),
                'object' => 'orders',
            ]);
            $ids = [];

            foreach ($comparison->differences as $difference) {
                $ids[] = $difference->id;
            }

            $this->assertSame(ConnectionStatus::Connected, $before->connection->status);
            $this->assertContains('column.orders.note.added', $ids);
            $this->assertSame('record-not-in-output', $this->value($pdo, 'select legacy from orders where id = 1'));
            $this->assertStringNotContainsString('record-not-in-output', $beforeJson);
            $this->assertStringNotContainsString('record-not-in-output', (string) json_encode($comparison->toArray()));

            if ($password !== '') {
                $this->assertStringNotContainsString($password, $beforeJson);
            }
        } finally {
            $pdo->exec('drop database if exists beacon_compare_it');
            config()->set('database.default', 'sqlite');
        }
    }

    private function value(\PDO $pdo, string $sql): string
    {
        $statement = $pdo->query($sql);
        $this->assertInstanceOf(\PDOStatement::class, $statement);
        $value = $statement->fetchColumn();
        $this->assertIsString($value);

        return $value;
    }

    private function env(string $name): ?string
    {
        $value = getenv($name);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
