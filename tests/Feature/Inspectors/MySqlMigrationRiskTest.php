<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Database\ConnectionStatus;
use Beacon\Inspectors\MigrationRiskInspector;
use Beacon\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Static risk analysis against MySQL and MariaDB.
 *
 * The migration file would drop a column. Beacon must report that without
 * dropping the column or changing the sentinel row.
 */
#[Group('mysql')]
final class MySqlMigrationRiskTest extends TestCase
{
    #[Test]
    public function mysql_drop_column_is_reported_and_not_executed(): void
    {
        $this->assertDriver('mysql', 'BEACON_MYSQL');
    }

    #[Test]
    public function mariadb_drop_column_is_reported_and_not_executed(): void
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
        $pdo->exec('drop database if exists beacon_risk_it');
        $pdo->exec('create database beacon_risk_it');
        $pdo->exec('use beacon_risk_it');
        $pdo->exec('create table migrations (id int unsigned not null auto_increment primary key, migration varchar(255) not null, batch int not null)');
        $pdo->exec('create table orders (id int unsigned not null primary key, legacy varchar(40) not null)');
        $pdo->exec("insert into orders (id, legacy) values (1, 'record-not-in-output')");
        $path = $this->application()->databasePath('migrations') . '/2099_03_01_000000_beacon_risk_mysql_drop.php';

        try {
            file_put_contents($path, <<<'PHP'
                <?php

                class BeaconRiskMysqlProbe
                {
                    public function up(): void
                    {
                        Schema::table('orders', function (Blueprint $table) {
                            $table->dropColumn('legacy');
                        });
                    }

                    public function down(): void
                    {
                    }
                }
                PHP);
            config()->set('database.default', 'beacon_risk');
            config()->set('database.connections.beacon_risk', [
                'driver' => $driver,
                'host' => $host,
                'port' => $port,
                'database' => 'beacon_risk_it',
                'username' => $username,
                'password' => $password,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ]);
            config()->set('beacon.security.allowed_capabilities', ['migration_risk', 'schema']);
            config()->set('beacon.migration_risk.timeout_seconds', 5);
            config()->set('beacon.schema.timeout_seconds', 5);

            $inspection = $this->application()->make(MigrationRiskInspector::class)->inspect([
                'migration' => '2099_03_01_000000_beacon_risk_mysql_drop',
                'enrich' => true,
            ]);
            $encoded = (string) json_encode($inspection->toArray());
            $drop = null;

            foreach ($inspection->findings as $finding) {
                if ($finding->ruleId === 'MIG002') {
                    $drop = $finding;
                }
            }

            $this->assertNotNull($drop);
            $this->assertSame('high', $drop->severity->value);
            $this->assertSame('pending', $drop->migrationStatus->value);
            $this->assertTrue($inspection->scope->enriched);
            $this->assertSame(ConnectionStatus::Connected, $inspection->connection->status);
            $this->assertSame('record-not-in-output', $this->value($pdo, 'select legacy from orders where id = 1'));
            $this->assertStringNotContainsString('record-not-in-output', $encoded);

            if ($password !== '') {
                $this->assertStringNotContainsString($password, $encoded);
            }
        } finally {
            @unlink($path);
            $pdo->exec('drop database if exists beacon_risk_it');
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
