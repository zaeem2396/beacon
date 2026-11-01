<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Database\ConnectionStatus;
use Beacon\Inspectors\MigrationInspector;
use Beacon\Migrations\MigrationStatus;
use Beacon\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Optional check against the CommerceOps migration history.
 *
 * Beacon does not depend on that application. The test runs only when
 * BEACON_COMMERCEOPS_HOST is set and the application directory is present.
 * It adds one pending fixture under storage and deletes it afterward.
 */
#[Group('commerceops')]
final class CommerceOpsMigrationTest extends TestCase
{
    #[Test]
    public function commerceops_history_matches_its_migration_files(): void
    {
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql is not loaded.');
        }

        $host = $this->env('BEACON_COMMERCEOPS_HOST');
        $root = $this->env('BEACON_COMMERCEOPS_PATH');

        if ($host === null || $root === null || ! is_dir($root . '/database/migrations')) {
            $this->markTestSkipped('CommerceOps is not available for migration inspection.');
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
        $before = $this->intColumn($pdo, 'select count(*) from users');
        $probe = $root . '/storage/beacon-migration-probe';
        $previous = $this->application()->basePath();

        try {
            mkdir($probe, 0o777, true);
            file_put_contents($probe . '/2099_01_01_000000_beacon_pending_probe.php', <<<'PHP'
                <?php

                file_put_contents(__DIR__ . '/ran.txt', 'migration-php-was-read');
                PHP);
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
            config()->set('beacon.security.allowed_capabilities', ['migrations']);
            config()->set('beacon.migrations.timeout_seconds', 5);
            $this->application()->make('migrator')->path($probe);

            $inspection = $this->application()->make(MigrationInspector::class)->inspect(['limit' => 100]);
            $encoded = (string) json_encode($inspection->toArray());

            $this->assertSame($before, $this->intColumn($pdo, 'select count(*) from users'));
            $this->assertFileDoesNotExist($probe . '/ran.txt');
            $this->assertSame(ConnectionStatus::Connected, $inspection->connection->status);
            $this->assertTrue($inspection->repository->available);
            $this->assertGreaterThanOrEqual(4, $inspection->summary->executed);
            $this->assertGreaterThanOrEqual(1, $inspection->summary->pending);
            $pending = false;

            foreach ($inspection->migrations as $migration) {
                if ($migration->name === '2099_01_01_000000_beacon_pending_probe') {
                    $pending = $migration->status === MigrationStatus::Pending;
                }
            }

            $this->assertTrue($pending);

            if ($password !== '') {
                $this->assertStringNotContainsString($password, $encoded);
            }

            $this->assertStringNotContainsString($host, $encoded);
            $this->assertStringNotContainsString('migration-php-was-read', $encoded);
        } finally {
            $this->application()->setBasePath($previous);
            $this->remove($probe);
            config()->set('database.default', 'sqlite');
        }
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
            is_dir($child) ? $this->remove($child) : unlink($child);
        }

        rmdir($path);
    }
}
