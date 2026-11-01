<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Core\Beacon;
use Beacon\Data\MigrationInspection;
use Beacon\Database\ConnectionStatus;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InvalidMigrationQueryException;
use Beacon\Inspectors\MigrationInspector;
use Beacon\Migrations\MigrationHistory;
use Beacon\Migrations\MigrationScanner;
use Beacon\Migrations\MigrationSourceKind;
use Beacon\Migrations\MigrationStatus;
use Beacon\Security\Principal;
use Beacon\Tests\Fixtures\RecordingMigrationHistory;
use Beacon\Tests\Fixtures\RecordingMigrationScanner;
use Beacon\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class MigrationInspectorTest extends TestCase
{
    #[Test]
    public function an_unlisted_connection_is_not_opened_or_scanned(): void
    {
        [$history, $scanner] = $this->recorders();

        $inspection = $this->inspect(['connection' => 'reports']);

        $this->assertSame(ConnectionStatus::Unknown, $inspection->connection->status);
        $this->assertNull($inspection->connection->name);
        $this->assertSame(0, $history->calls);
        $this->assertSame(0, $scanner->calls);
        $this->assertStringNotContainsString('reports', (string) json_encode($inspection->toArray()));
    }

    #[Test]
    public function postgresql_is_identified_and_not_opened(): void
    {
        [$history, $scanner] = $this->recorders();
        config()->set('database.default', 'pgsql');
        config()->set('database.connections.pgsql.driver', 'pgsql');
        config()->set('database.connections.pgsql.password', 'migration-password-not-in-output');

        $inspection = $this->inspect();

        $this->assertSame(ConnectionStatus::Unsupported, $inspection->connection->status);
        $this->assertSame('pgsql', $inspection->connection->driver);
        $this->assertSame(0, $history->calls);
        $this->assertSame(1, $scanner->calls);
        $this->assertStringNotContainsString('migration-password-not-in-output', (string) json_encode($inspection->toArray()));
    }

    #[Test]
    public function an_unregistered_path_is_rejected_before_the_repository_is_read(): void
    {
        [$history] = $this->recorders();

        try {
            $this->inspect(['path' => 'database/not-registered']);
            $this->fail('The path filter was accepted.');
        } catch (InvalidMigrationQueryException $exception) {
            $this->assertStringNotContainsString('not-registered', $exception->getMessage());
        }

        $this->assertSame(0, $history->calls);
    }

    #[Test]
    public function traversal_is_rejected_before_discovery(): void
    {
        [$history, $scanner] = $this->recorders();

        try {
            $this->inspect(['path' => '../outside']);
            $this->fail('The path filter was accepted.');
        } catch (InvalidMigrationQueryException $exception) {
            $this->assertStringNotContainsString('outside', $exception->getMessage());
        }

        $this->assertSame(0, $history->calls);
        $this->assertSame(0, $scanner->calls);
    }

    #[Test]
    public function the_migrations_capability_is_required(): void
    {
        [$history, $scanner] = $this->recorders();
        config()->set('beacon.security.allowed_capabilities', ['database', 'schema']);

        try {
            $this->application()->make(Beacon::class)->inspect(Principal::local(), MigrationInspector::NAME);
            $this->fail('Schema permission granted migration inspection.');
        } catch (AuthorizationException) {
            $this->assertSame(0, $history->calls);
            $this->assertSame(0, $scanner->calls);
        }
    }

    #[Test]
    public function a_migration_file_is_not_executed(): void
    {
        $root = base_path('beacon-migration-it');
        $directory = $root . '/database/migrations';
        mkdir($directory, 0o777, true);
        $php = $directory . '/2026_04_01_000000_beacon_side_effect.php';
        $marker = $directory . '/ran.txt';
        $sqlite = database_path('database.sqlite');
        $sqliteExisted = is_file($sqlite);
        file_put_contents($php, <<<'PHP'
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
        $before = md5((string) file_get_contents($php));
        $this->application()->make('migrator')->path($directory);

        try {
            $inspection = $this->inspect(['name' => 'beacon_side_effect']);
            $encoded = (string) json_encode($inspection->toArray());

            $this->assertFileDoesNotExist($marker);
            $this->assertFalse(class_exists('BeaconMigrationSideEffectProbe', false));
            $this->assertSame($before, md5((string) file_get_contents($php)));
            $this->assertSame($sqliteExisted, is_file($sqlite));
            $this->assertCount(1, $inspection->migrations);
            $this->assertNull($inspection->migrations[0]->batch);
            $this->assertContains($inspection->migrations[0]->status, [MigrationStatus::Pending, MigrationStatus::Unknown]);
            $this->assertSame(MigrationSourceKind::Registered, $inspection->migrations[0]->source);
            $this->assertStringNotContainsString('migration-php-was-read', $encoded);
            $this->assertStringNotContainsString($root, $encoded);
        } finally {
            $this->remove($root);
        }
    }

    /**
     * @return array{RecordingMigrationHistory, RecordingMigrationScanner}
     */
    private function recorders(): array
    {
        $history = new RecordingMigrationHistory();
        $scanner = new RecordingMigrationScanner();
        $this->application()->instance(MigrationHistory::class, $history);
        $this->application()->instance(MigrationScanner::class, $scanner);

        return [$history, $scanner];
    }

    /**
     * @param array<string, bool|float|int|string|null> $parameters
     */
    private function inspect(array $parameters = []): MigrationInspection
    {
        config()->set('beacon.security.allowed_capabilities', ['migrations']);
        $inspection = $this->application()->make(MigrationInspector::class)->inspect($parameters);
        $this->assertInstanceOf(MigrationInspection::class, $inspection);

        return $inspection;
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
