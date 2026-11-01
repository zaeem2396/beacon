<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Data\SchemaColumn;
use Beacon\Data\SchemaInspection;
use Beacon\Data\SchemaObject;
use Beacon\Database\ConnectionStatus;
use Beacon\Inspectors\SchemaInspector;
use Beacon\Schema\ObjectType;
use Beacon\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Schema inspection against a real MySQL or MariaDB server.
 *
 * The test creates and drops its own tables. It skips when the corresponding
 * environment variables are absent, so the default suite does not need Docker.
 */
#[Group('mysql')]
final class MySqlSchemaInspectorTest extends TestCase
{
    private const SECRET = 'schema-password-not-in-output';

    private const SENTINEL = 'record-not-in-output';

    #[Test]
    public function mysql_metadata_matches_the_database_and_the_schema_is_unchanged(): void
    {
        $this->assertDriver('mysql', 'BEACON_MYSQL');
    }

    #[Test]
    public function mariadb_metadata_matches_the_database_and_the_schema_is_unchanged(): void
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
        $database = 'beacon_schema_it';
        $pdo = new \PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, (int) $port),
            $username,
            $password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec('drop database if exists beacon_schema_it');
        $pdo->exec('create database beacon_schema_it');
        $pdo->exec('use beacon_schema_it');
        $this->install($pdo);

        try {
            $before = $this->snapshot($pdo);
            config()->set('database.default', 'beacon_schema');
            config()->set('database.connections.beacon_schema', [
                'driver' => $driver,
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ]);
            config()->set('beacon.security.allowed_capabilities', ['schema']);
            config()->set('beacon.schema.timeout_seconds', 5);
            config()->set('beacon.schema.exclude', ['beacon_it_labels']);

            $inspection = $this->application()->make(SchemaInspector::class)->inspect(['limit' => 50]);
            $this->assertInstanceOf(SchemaInspection::class, $inspection);
            $encoded = (string) json_encode($inspection->toArray());
            $after = $this->snapshot($pdo);

            $this->assertSame($before, $after);
            $this->assertSame(ConnectionStatus::Connected, $inspection->connection->status);
            $this->assertSame($driver, $inspection->connection->driver);
            $this->assertSame($database, $inspection->connection->database);
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(?:-MariaDB)?$/', (string) $inspection->connection->version);
            $this->assertStringNotContainsString(self::SECRET, $encoded);
            $this->assertStringNotContainsString(self::SENTINEL, $encoded);

            if ($password !== '') {
                $this->assertStringNotContainsString($password, $encoded);
            }

            $this->assertStringNotContainsString($host, $encoded);

            $orders = $this->object($inspection, 'beacon_it_orders');
            $items = $this->object($inspection, 'beacon_it_items');
            $accounts = $this->object($inspection, 'beacon_it_accounts');
            $secrets = $this->object($inspection, 'beacon_it_secrets');
            $view = $this->object($inspection, 'beacon_it_order_names');

            $this->assertSame(ObjectType::Table, $orders->type);
            $this->assertSame(['id'], $orders->primaryKey);
            $this->assertNotNull($orders->rowEstimate);
            $this->assertTrue($orders->estimated);
            $this->assertTrue($this->column($orders, 'id')->autoIncrement);
            $this->assertTrue($this->column($orders, 'id')->unsigned);
            $this->assertSame(10, $this->column($orders, 'total')->numericPrecision);
            $this->assertSame(2, $this->column($orders, 'total')->numericScale);
            $this->assertTrue($this->column($orders, 'note')->nullable);
            $this->assertFalse($this->column($orders, 'total')->nullable);
            $this->assertTrue($this->column($orders, 'active')->booleanLike);
            $this->assertSame('CURRENT_TIMESTAMP', $this->column($orders, 'placed_at')->defaultValue);
            $this->assertTrue($this->column($secrets, 'password')->defaultSensitive);
            $this->assertNull($this->column($secrets, 'password')->defaultValue);
            $this->assertContains('beacon_it_orders_uuid_unique', array_map(static fn ($constraint) => $constraint->name, $orders->uniqueConstraints));
            $placed = null;

            foreach ($orders->indexes as $index) {
                if ($index->name === 'beacon_it_orders_placed_index') {
                    $placed = $index;
                }
            }

            $this->assertNotNull($placed);
            $this->assertFalse($placed->unique);
            $this->assertSame(['placed_at'], $placed->columns);
            $this->assertSame(['id'], $accounts->primaryKey);
            $this->assertSame('char', $this->column($accounts, 'id')->typeName);
            $this->assertSame([], $this->object($inspection, 'beacon_it_plain')->primaryKey);
            $this->assertNull($this->find($inspection, 'beacon_it_labels'));
            $this->assertSame(['order_id', 'line_no'], $items->primaryKey);
            $this->assertSame('beacon_it_orders', $items->foreignKeys[0]->referencedTable);
            $this->assertSame(['id'], $items->foreignKeys[0]->referencedColumns);
            $this->assertSame('cascade', $items->foreignKeys[0]->onUpdate->value);
            $this->assertSame('cascade', $items->foreignKeys[0]->onDelete->value);
            $this->assertSame(ObjectType::View, $view->type);
            $this->assertNull($view->rowEstimate);
            $this->assertSame(['id', 'uuid'], array_map(static fn (SchemaColumn $column): string => $column->name, $view->columns));

            if ($driver === 'mysql') {
                $this->assertTrue($this->column($orders, 'meta')->json);
            }

            $filtered = $this->application()->make(SchemaInspector::class)->inspect([
                'table' => 'beacon_it_labels',
                'limit' => 50,
            ]);
            $this->assertInstanceOf(SchemaInspection::class, $filtered);
            $this->assertSame(0, $filtered->summary->tableCount);
            $this->assertSame([], $filtered->objects);
            $this->assertSame(1, $this->intColumn($pdo, 'select count(*) from beacon_it_orders'));
            $this->assertSame(self::SENTINEL, $this->stringColumn($pdo, 'select note from beacon_it_orders'));
        } finally {
            $pdo->exec('drop database if exists beacon_schema_it');
            config()->set('database.default', 'sqlite');
        }
    }

    private function install(\PDO $pdo): void
    {
        $this->remove($pdo);
        $pdo->exec(<<<'SQL'
            create table beacon_it_orders (
                id bigint unsigned not null auto_increment,
                uuid char(36) not null,
                total decimal(10,2) not null default 0.00,
                meta json null,
                placed_at timestamp null default current_timestamp,
                active tinyint(1) not null default 1,
                note varchar(100) null,
                primary key (id),
                unique key beacon_it_orders_uuid_unique (uuid),
                key beacon_it_orders_placed_index (placed_at)
            )
            SQL);
        $pdo->exec(<<<'SQL'
            create table beacon_it_items (
                order_id bigint unsigned not null,
                line_no int unsigned not null,
                sku varchar(32) not null,
                primary key (order_id, line_no),
                constraint beacon_it_items_order_fk foreign key (order_id) references beacon_it_orders (id) on update cascade on delete cascade
            )
            SQL);
        $pdo->exec('create table beacon_it_accounts (id char(36) not null, primary key (id))');
        $pdo->exec('create table beacon_it_plain (name varchar(50) not null)');
        $pdo->exec("create table beacon_it_secrets (id bigint unsigned not null auto_increment primary key, password varchar(100) not null default '" . self::SECRET . "')");
        $pdo->exec('create table beacon_it_labels (name varchar(50) not null)');
        $pdo->exec('create view beacon_it_order_names as select id, uuid from beacon_it_orders');
        $statement = $pdo->prepare('insert into beacon_it_orders (uuid, note) values (?, ?)');
        $statement->execute(['11111111-1111-1111-1111-111111111111', self::SENTINEL]);
    }

    private function remove(\PDO $pdo): void
    {
        $pdo->exec('drop view if exists beacon_it_order_names');
        $pdo->exec('drop table if exists beacon_it_items');
        $pdo->exec('drop table if exists beacon_it_secrets');
        $pdo->exec('drop table if exists beacon_it_labels');
        $pdo->exec('drop table if exists beacon_it_plain');
        $pdo->exec('drop table if exists beacon_it_accounts');
        $pdo->exec('drop table if exists beacon_it_orders');
    }

    /**
     * @return array{creates: array<string, string>, rows: int}
     */
    private function snapshot(\PDO $pdo): array
    {
        $creates = [];

        foreach (['beacon_it_orders', 'beacon_it_items', 'beacon_it_accounts', 'beacon_it_plain', 'beacon_it_secrets', 'beacon_it_labels'] as $table) {
            $creates[$table] = $this->createStatement($pdo, 'show create table `' . $table . '`');
        }

        $creates['beacon_it_order_names'] = $this->createStatement($pdo, 'show create view `beacon_it_order_names`');

        return [
            'creates' => $creates,
            'rows' => $this->intColumn($pdo, 'select count(*) from beacon_it_orders'),
        ];
    }

    private function createStatement(\PDO $pdo, string $sql): string
    {
        $statement = $pdo->query($sql);
        $this->assertInstanceOf(\PDOStatement::class, $statement);
        $row = $statement->fetch(\PDO::FETCH_NUM);
        $this->assertIsArray($row);
        $definition = $row[1] ?? null;
        $this->assertIsString($definition);

        return $definition;
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

    private function stringColumn(\PDO $pdo, string $sql): string
    {
        $statement = $pdo->query($sql);
        $this->assertInstanceOf(\PDOStatement::class, $statement);
        $value = $statement->fetchColumn();
        $this->assertIsString($value);

        return $value;
    }

    private function object(SchemaInspection $inspection, string $name): SchemaObject
    {
        $object = $this->find($inspection, $name);
        $this->assertNotNull($object, $name);

        return $object;
    }

    private function find(SchemaInspection $inspection, string $name): ?SchemaObject
    {
        foreach ($inspection->objects as $object) {
            if ($object->name === $name) {
                return $object;
            }
        }

        return null;
    }

    private function column(SchemaObject $object, string $name): SchemaColumn
    {
        foreach ($object->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        $this->fail('Missing column ' . $name);
    }

    private function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
