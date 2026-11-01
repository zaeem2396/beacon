<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Data\MigrationEntry;
use Beacon\Data\MigrationInspection;
use Beacon\Database\ConnectionStatus;
use Beacon\Inspectors\MigrationInspector;
use Beacon\Migrations\MigrationStatus;
use Beacon\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Migration inspection against a real MySQL or MariaDB server.
 *
 * The test creates and drops its own database. Migration PHP is written as a
 * fixture and must not run. The test skips when the database environment is
 * absent, so the default suite does not need Docker.
 */
#[Group('mysql')]
final class MySqlMigrationInspectorTest extends TestCase
{
    private const SECRET = 'migration-password-not-in-output';

    private const SENTINEL = 'record-not-in-output';

    #[Test]
    public function mysql_history_matches_the_files_and_nothing_is_executed(): void
    {
        $this->assertDriver('mysql', 'BEACON_MYSQL');
    }

    #[Test]
    public function mariadb_history_matches_the_files_and_nothing_is_executed(): void
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
        $pdo->exec('drop database if exists beacon_migrations_it');
        $pdo->exec('create database beacon_migrations_it');
        $pdo->exec('use beacon_migrations_it');
        $this->install($pdo);
        $paths = $this->installFiles();
        $hashes = $this->hashes($paths['files']);

        try {
            $before = $this->snapshot($pdo);
            config()->set('database.default', 'beacon_migrations');
            config()->set('database.connections.beacon_migrations', [
                'driver' => $driver,
                'host' => $host,
                'port' => $port,
                'database' => 'beacon_migrations_it',
                'username' => $username,
                'password' => $password,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ]);
            config()->set('database.migrations.table', 'beacon_migration_log');
            config()->set('beacon.security.allowed_capabilities', ['migrations']);
            config()->set('beacon.migrations.timeout_seconds', 5);
            $this->application()->make('migrator')->path($paths['extra']);
            $this->application()->make('migrator')->path($paths['empty']);

            $inspection = $this->inspect();
            $encoded = (string) json_encode($inspection->toArray());

            $this->assertSame($before, $this->snapshot($pdo));
            $this->assertSame($hashes, $this->hashes($paths['files']));
            $this->assertFileDoesNotExist($paths['marker']);
            $this->assertFalse(class_exists('BeaconMigrationSideEffectProbe', false));
            $this->assertSame(ConnectionStatus::Connected, $inspection->connection->status);
            $this->assertSame($driver, $inspection->connection->driver);
            $this->assertSame('beacon_migration_log', $inspection->repository->table);
            $this->assertTrue($inspection->repository->available);
            $this->assertFalse($inspection->repository->empty);
            $this->assertSame(6, $inspection->summary->fileCount);
            $this->assertSame(2, $inspection->summary->executed);
            $this->assertSame(2, $inspection->summary->pending);
            $this->assertSame(1, $inspection->summary->missingFiles);
            $this->assertSame(1, $inspection->summary->duplicateNames);
            $this->assertSame(2, $inspection->summary->batchCount);
            $this->assertSame(MigrationStatus::Executed, $this->named($inspection, '2026_01_01_000000_create_users_table')->status);
            $this->assertSame(1, $this->named($inspection, '2026_01_01_000000_create_users_table')->batch);
            $this->assertSame(1, $this->named($inspection, '2026_01_01_000001_create_merchants_table')->batch);
            $this->assertSame(MigrationStatus::Duplicate, $this->named($inspection, '2026_01_02_000000_create_orders_table')->status);
            $this->assertSame(1, $this->named($inspection, '2026_01_02_000000_create_orders_table')->batch);
            $this->assertSame(MigrationStatus::Pending, $this->named($inspection, '2026_02_01_000000_add_order_notes')->status);
            $this->assertNull($this->named($inspection, '2026_02_01_000000_add_order_notes')->batch);
            $this->assertSame(MigrationStatus::Pending, $this->named($inspection, '2026_04_01_000000_beacon_side_effect')->status);
            $missing = $this->named($inspection, '2026_01_03_000000_removed_widget');
            $this->assertSame(MigrationStatus::MissingFile, $missing->status);
            $this->assertSame(2, $missing->batch);
            $this->assertFalse($missing->fileExists);
            $this->assertNull($missing->relativePath);
            $this->assertSame([1, 2], array_map(static fn ($batch) => $batch->batch, $inspection->batches));
            $this->assertSame(3, $inspection->batches[0]->count);
            $this->assertNotContains('migrations', $this->tables($pdo));
            $page = $this->inspect(['limit' => 1]);
            $this->assertSame(1, $page->pagination->count);
            $this->assertSame(7, $page->pagination->total);
            $filtered = $this->inspect(['path' => 'beacon-migration-it/extra']);
            $this->assertCount(1, $filtered->migrations);
            $this->assertSame(MigrationStatus::Duplicate, $filtered->migrations[0]->status);

            foreach ([$password, $host, self::SECRET, self::SENTINEL, 'migration-php-was-read'] as $secret) {
                if ($secret !== '') {
                    $this->assertStringNotContainsString($secret, $encoded);
                }
            }

            $pdo->exec('drop table beacon_migration_log');
            $missingRepository = $this->inspect(['name' => 'create_users']);
            $this->assertFalse($missingRepository->repository->available);
            $this->assertSame(MigrationStatus::Unknown, $missingRepository->migrations[0]->status);
            $this->assertNotContains('beacon_migration_log', $this->tables($pdo));
            $this->assertSame(self::SENTINEL, $this->stringColumn($pdo, 'select note from beacon_it_records'));
        } finally {
            $pdo->exec('drop database if exists beacon_migrations_it');
            $this->remove($paths['root']);
            foreach ($paths['files'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            config()->set('database.default', 'sqlite');
        }
    }

    private function install(\PDO $pdo): void
    {
        $pdo->exec('create table beacon_migration_log (id int unsigned not null auto_increment primary key, migration varchar(255) not null, batch int not null)');
        $pdo->exec('create table beacon_it_records (id int unsigned not null primary key, note varchar(100) not null)');
        $pdo->exec("insert into beacon_it_records (id, note) values (1, '" . self::SENTINEL . "')");
        $insert = $pdo->prepare('insert into beacon_migration_log (migration, batch) values (?, ?)');
        $this->assertNotFalse($insert);

        foreach ([
            ['2026_01_01_000000_create_users_table', 1],
            ['2026_01_01_000001_create_merchants_table', 1],
            ['2026_01_02_000000_create_orders_table', 1],
            ['2026_01_03_000000_removed_widget', 2],
        ] as $row) {
            $insert->execute($row);
        }
    }

    /**
     * @return array{root: string, extra: string, empty: string, marker: string, files: list<string>}
     */
    private function installFiles(): array
    {
        $root = base_path('beacon-migration-it');
        $extra = $root . '/extra';
        $empty = $root . '/empty';
        mkdir($extra, 0o777, true);
        mkdir($empty, 0o777, true);
        $application = database_path('migrations');
        $files = [
            $application . '/2026_01_01_000000_create_users_table.php',
            $application . '/2026_01_01_000001_create_merchants_table.php',
            $application . '/2026_01_02_000000_create_orders_table.php',
            $application . '/2026_02_01_000000_add_order_notes.php',
            $application . '/2026_04_01_000000_beacon_side_effect.php',
            $extra . '/2026_01_02_000000_create_orders_table.php',
        ];

        foreach ($files as $file) {
            file_put_contents($file, <<<'PHP'
                <?php

                file_put_contents(__DIR__ . '/ran.txt', 'migration-php-was-read');

                class BeaconMigrationSideEffectProbe
                {
                    public function up(): void
                    {
                        file_put_contents(__DIR__ . '/ran.txt', 'migration-php-was-read');
                    }

                    public function down(): void
                    {
                        file_put_contents(__DIR__ . '/ran.txt', 'migration-php-was-read');
                    }
                }
                PHP);
        }

        return [
            'root' => $root,
            'extra' => $extra,
            'empty' => $empty,
            'marker' => $application . '/ran.txt',
            'files' => $files,
        ];
    }

    /**
     * @param list<string> $files
     *
     * @return array<string, string>
     */
    private function hashes(array $files): array
    {
        $hashes = [];

        foreach ($files as $file) {
            $hashes[$file] = md5((string) file_get_contents($file));
        }

        return $hashes;
    }

    /**
     * @return array{log: string, records: string, tables: list<string>}
     */
    private function snapshot(\PDO $pdo): array
    {
        return [
            'log' => $this->rows($pdo, 'select migration, batch from beacon_migration_log order by id'),
            'records' => $this->rows($pdo, 'select id, note from beacon_it_records order by id'),
            'tables' => $this->tables($pdo),
        ];
    }

    private function rows(\PDO $pdo, string $sql): string
    {
        $statement = $pdo->query($sql);
        $this->assertInstanceOf(\PDOStatement::class, $statement);
        $encoded = json_encode($statement->fetchAll(\PDO::FETCH_NUM));
        $this->assertIsString($encoded);

        return $encoded;
    }

    /**
     * @return list<string>
     */
    private function tables(\PDO $pdo): array
    {
        $statement = $pdo->query('show tables');
        $this->assertInstanceOf(\PDOStatement::class, $statement);
        $tables = [];

        while (($value = $statement->fetchColumn()) !== false) {
            $this->assertIsString($value);
            $tables[] = $value;
        }

        sort($tables);

        return $tables;
    }

    private function stringColumn(\PDO $pdo, string $sql): string
    {
        $statement = $pdo->query($sql);
        $this->assertInstanceOf(\PDOStatement::class, $statement);
        $value = $statement->fetchColumn();
        $this->assertIsString($value);

        return $value;
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function inspect(array $parameters = []): MigrationInspection
    {
        $inspection = $this->application()->make(MigrationInspector::class)->inspect($parameters);
        $this->assertInstanceOf(MigrationInspection::class, $inspection);

        return $inspection;
    }

    private function named(MigrationInspection $inspection, string $name): MigrationEntry
    {
        foreach ($inspection->migrations as $migration) {
            if ($migration->name === $name) {
                return $migration;
            }
        }

        $this->fail('Missing migration ' . $name);
    }

    private function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        $items = $items === false ? [] : $items;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . '/' . $item;

            if (is_dir($child)) {
                $this->remove($child);
            } else {
                unlink($child);
            }
        }

        rmdir($path);
    }
}
