<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Inspectors\SchemaComparisonInspector;
use Beacon\Inspectors\SchemaSnapshotInspector;
use Beacon\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Schema comparison against CommerceOps MySQL.
 *
 * Changes are limited to beacon_cmp tables and are removed afterward.
 * CommerceOps migrations and the users table are left unchanged.
 */
#[Group('commerceops')]
final class CommerceOpsSchemaComparisonTest extends TestCase
{
    #[Test]
    public function controlled_structure_changes_are_classified(): void
    {
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql is not loaded.');
        }

        $host = $this->env('BEACON_COMMERCEOPS_HOST');
        $root = $this->env('BEACON_COMMERCEOPS_PATH');

        if ($host === null || $root === null || ! is_dir($root . '/database/migrations')) {
            $this->markTestSkipped('CommerceOps is not available for schema comparison.');
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
        $beforeUsers = $this->countRows($pdo, 'select count(*) from users');
        $previous = $this->application()->basePath();

        try {
            $this->reset($pdo);
            $this->baseline($pdo);
            $this->application()->setBasePath($root);
            config()->set('database.default', 'commerceops');
            config()->set('database.connections.commerceops', [
                'driver' => 'mysql',
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'username' => $username,
                'password' => $password,
            ]);
            config()->set('beacon.security.allowed_capabilities', ['schema', 'schema_snapshot', 'schema_compare']);
            config()->set('beacon.schema.timeout_seconds', 5);

            $first = $this->application()->make(SchemaSnapshotInspector::class)->inspect([]);
            $this->mutate($pdo);
            $second = $this->application()->make(SchemaSnapshotInspector::class)->inspect([]);
            $comparison = $this->application()->make(SchemaComparisonInspector::class)->inspect([
                'baseline' => (string) json_encode(['snapshot' => $first->snapshot->toArray()]),
                'candidate' => (string) json_encode(['snapshot' => $second->snapshot->toArray()]),
                'limit' => 100,
            ]);
            $ids = [];

            foreach ($comparison->differences as $difference) {
                if ($difference->table === null || str_starts_with($difference->table, 'beacon_cmp') || str_starts_with($difference->objectName, 'beacon_cmp')) {
                    $ids[] = $difference->id;
                }
            }

            $this->assertContains('table.beacon_cmp_audit.added', $ids);
            $this->assertContains('table.beacon_cmp_retire.removed', $ids);
            $this->assertContains('column.beacon_cmp_notes.note.added', $ids);
            $this->assertContains('column.beacon_cmp_notes.legacy.removed', $ids);
            $this->assertContains('column.beacon_cmp_orders.code.modified.character_length', $ids);
            $this->assertContains('column.beacon_cmp_notes.state.modified.nullable', $ids);
            $this->assertContains('index.beacon_cmp_notes.beacon_cmp_notes_state_index.added', $ids);
            $this->assertContains('index.beacon_cmp_notes.beacon_cmp_notes_legacy_index.removed', $ids);
            $this->assertContains('foreign_key.beacon_cmp_notes.beacon_cmp_notes_order_id_foreign.modified.on_delete', $ids);
            $this->assertContains('view.beacon_cmp_labels.legacy.removed', $ids);
            $this->assertSame($beforeUsers, $this->countRows($pdo, 'select count(*) from users'));

            if ($password !== '') {
                $this->assertStringNotContainsString($password, (string) json_encode($comparison->toArray()));
            }
        } finally {
            $this->reset($pdo);
            $this->application()->setBasePath($previous);
            config()->set('database.default', 'sqlite');
        }
    }

    private function baseline(\PDO $pdo): void
    {
        $pdo->exec('create table beacon_cmp_orders (id int unsigned not null primary key, code varchar(40) not null, total decimal(10, 2) not null)');
        $pdo->exec('create table beacon_cmp_retire (id int unsigned not null primary key)');
        $pdo->exec('create table beacon_cmp_notes (id int unsigned not null primary key, order_id int unsigned not null, legacy varchar(40) not null, state varchar(10) not null default \'open\', index beacon_cmp_notes_legacy_index (legacy), constraint beacon_cmp_notes_order_id_foreign foreign key (order_id) references beacon_cmp_orders (id) on delete restrict on update restrict)');
        $pdo->exec('create view beacon_cmp_labels as select id, legacy from beacon_cmp_notes');
    }

    private function mutate(\PDO $pdo): void
    {
        $pdo->exec('drop view beacon_cmp_labels');
        $pdo->exec('create table beacon_cmp_audit (id int unsigned not null primary key)');
        $pdo->exec('drop table beacon_cmp_retire');
        $pdo->exec('alter table beacon_cmp_notes add note varchar(20) null');
        $pdo->exec('alter table beacon_cmp_notes drop index beacon_cmp_notes_legacy_index');
        $pdo->exec('alter table beacon_cmp_notes drop column legacy');
        $pdo->exec('alter table beacon_cmp_orders modify code varchar(20) not null');
        $pdo->exec('alter table beacon_cmp_notes modify state varchar(10) null default \'open\'');
        $pdo->exec('alter table beacon_cmp_notes add index beacon_cmp_notes_state_index (state)');
        $pdo->exec('alter table beacon_cmp_notes drop foreign key beacon_cmp_notes_order_id_foreign');
        $pdo->exec('alter table beacon_cmp_notes add constraint beacon_cmp_notes_order_id_foreign foreign key (order_id) references beacon_cmp_orders (id) on delete cascade on update restrict');
        $pdo->exec('create view beacon_cmp_labels as select id, state from beacon_cmp_notes');
    }

    private function reset(\PDO $pdo): void
    {
        $pdo->exec('drop view if exists beacon_cmp_labels');
        $pdo->exec('drop table if exists beacon_cmp_notes');
        $pdo->exec('drop table if exists beacon_cmp_retire');
        $pdo->exec('drop table if exists beacon_cmp_audit');
        $pdo->exec('drop table if exists beacon_cmp_orders');
    }

    private function countRows(\PDO $pdo, string $sql): int
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

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
