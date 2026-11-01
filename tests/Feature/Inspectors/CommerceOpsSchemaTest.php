<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Data\SchemaInspection;
use Beacon\Database\ConnectionStatus;
use Beacon\Inspectors\SchemaInspector;
use Beacon\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Optional check against the CommerceOps MySQL schema.
 *
 * Beacon does not depend on that application. The test runs only when
 * BEACON_COMMERCEOPS_HOST is set, and it does not encode CommerceOps rules
 * into the inspector.
 */
#[Group('commerceops')]
final class CommerceOpsSchemaTest extends TestCase
{
    #[Test]
    public function a_real_application_schema_can_be_read_without_changing_it(): void
    {
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql is not loaded.');
        }

        $host = $this->env('BEACON_COMMERCEOPS_HOST');

        if ($host === null) {
            $this->markTestSkipped('BEACON_COMMERCEOPS_HOST is not set.');
        }

        $database = $this->env('BEACON_COMMERCEOPS_DATABASE') ?? 'commerceops';
        $username = $this->env('BEACON_COMMERCEOPS_USERNAME') ?? 'commerceops';
        $password = $this->env('BEACON_COMMERCEOPS_PASSWORD') ?? '';
        $port = $this->env('BEACON_COMMERCEOPS_PORT') ?? '33060';
        $pdo = new \PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, (int) $port, $database),
            $username,
            $password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $before = $this->counts($pdo);

        try {
            config()->set('database.default', 'commerceops');
            config()->set('database.connections.commerceops', [
                'driver' => 'mysql',
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'username' => $username,
                'password' => $password,
            ]);
            config()->set('beacon.security.allowed_capabilities', ['schema']);
            config()->set('beacon.schema.timeout_seconds', 5);

            $inspection = $this->application()->make(SchemaInspector::class)->inspect(['limit' => 50]);
            $this->assertInstanceOf(SchemaInspection::class, $inspection);
            $encoded = (string) json_encode($inspection->toArray());

            $this->assertSame($before, $this->counts($pdo));
            $this->assertSame(ConnectionStatus::Connected, $inspection->connection->status);
            $this->assertSame('mysql', $inspection->connection->driver);
            $this->assertGreaterThanOrEqual(15, $inspection->summary->tableCount);
            $this->assertGreaterThan(0, $this->foreignKeyCount($inspection));
            $this->assertTrue($this->hasJsonColumn($inspection));

            foreach (['users', 'merchants', 'products', 'customers', 'orders', 'order_items', 'payments', 'inventory_movements', 'shipments', 'operational_reports'] as $name) {
                $this->assertTrue($this->hasObject($inspection, $name), $name);
            }

            if ($password !== '') {
                $this->assertStringNotContainsString($password, $encoded);
            }

            $this->assertStringNotContainsString($host, $encoded);
            $sample = $this->sample($pdo, 'select email from users limit 1');

            if ($sample !== null) {
                $this->assertStringNotContainsString($sample, $encoded);
            }
        } finally {
            config()->set('database.default', 'sqlite');
        }
    }

    /**
     * @return array<string, int>
     */
    private function counts(\PDO $pdo): array
    {
        $counts = [];

        foreach (['users', 'orders', 'payments', 'products'] as $table) {
            $counts[$table] = $this->intColumn($pdo, 'select count(*) from `' . $table . '`');
        }

        return $counts;
    }

    private function foreignKeyCount(SchemaInspection $inspection): int
    {
        $count = 0;

        foreach ($inspection->objects as $object) {
            $count += $object->foreignKeyCount;
        }

        return $count;
    }

    private function hasJsonColumn(SchemaInspection $inspection): bool
    {
        foreach ($inspection->objects as $object) {
            foreach ($object->columns as $column) {
                if ($column->json) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasObject(SchemaInspection $inspection, string $name): bool
    {
        foreach ($inspection->objects as $object) {
            if ($object->name === $name) {
                return true;
            }
        }

        return false;
    }

    private function sample(\PDO $pdo, string $sql): ?string
    {
        $statement = $pdo->query($sql);
        $this->assertInstanceOf(\PDOStatement::class, $statement);
        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intColumn(\PDO $pdo, string $sql): int
    {
        $statement = $pdo->query($sql);
        $this->assertInstanceOf(\PDOStatement::class, $statement);
        $value = $statement->fetchColumn();

        if (is_int($value)) {
            return $value;
        }

        $this->assertIsString($value);
        $this->assertMatchesRegularExpression('/^\d+$/', $value);

        return (int) $value;
    }

    private function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
