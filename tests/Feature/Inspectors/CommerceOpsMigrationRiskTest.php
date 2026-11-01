<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Inspectors\MigrationRiskInspector;
use Beacon\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Optional risk analysis against CommerceOps migrations.
 *
 * Synthetic pending files are added under storage and deleted afterward.
 * The application's own migration files are not modified.
 */
#[Group('commerceops')]
final class CommerceOpsMigrationRiskTest extends TestCase
{
    #[Test]
    public function pending_fixtures_are_classified_without_executing_them(): void
    {
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql is not loaded.');
        }

        $host = $this->env('BEACON_COMMERCEOPS_HOST');
        $root = $this->env('BEACON_COMMERCEOPS_PATH');

        if ($host === null || $root === null || ! is_dir($root . '/database/migrations')) {
            $this->markTestSkipped('CommerceOps is not available for migration risk analysis.');
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
        $before = $this->countRows($pdo, 'select count(*) from users');
        $probe = $root . '/storage/beacon-risk-probe';
        $previous = $this->application()->basePath();
        $files = [
            '2099_04_01_000000_beacon_risk_drop_column.php' => <<<'PHP'
                <?php

                class BeaconCommerceDropColumn
                {
                    public function up(): void
                    {
                        Schema::table('users', function (Blueprint $table) {
                            $table->dropColumn('email');
                        });
                    }

                    public function down(): void
                    {
                    }
                }
                PHP,
            '2099_04_01_000001_beacon_risk_required_column.php' => <<<'PHP'
                <?php

                class BeaconCommerceRequiredColumn
                {
                    public function up(): void
                    {
                        Schema::table('users', function (Blueprint $table) {
                            $table->string('risk_note');
                        });
                    }

                    public function down(): void
                    {
                    }
                }
                PHP,
            '2099_04_01_000002_beacon_risk_unique.php' => <<<'PHP'
                <?php

                class BeaconCommerceUnique
                {
                    public function up(): void
                    {
                        Schema::table('users', function (Blueprint $table) {
                            $table->unique('name');
                        });
                    }

                    public function down(): void
                    {
                    }
                }
                PHP,
            '2099_04_01_000003_beacon_risk_index.php' => <<<'PHP'
                <?php

                class BeaconCommerceIndex
                {
                    public function up(): void
                    {
                        Schema::table('users', function (Blueprint $table) {
                            $table->index('name');
                        });
                    }

                    public function down(): void
                    {
                    }
                }
                PHP,
            '2099_04_01_000004_beacon_risk_backfill.php' => <<<'PHP'
                <?php

                class BeaconCommerceBackfill
                {
                    public function up(): void
                    {
                        DB::table('users')->orderBy('id')->get();
                    }

                    public function down(): void
                    {
                    }
                }
                PHP,
        ];

        try {
            mkdir($probe, 0o777, true);

            foreach ($files as $name => $contents) {
                file_put_contents($probe . '/' . $name, $contents);
            }

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
            config()->set('beacon.security.allowed_capabilities', ['migration_risk']);
            config()->set('beacon.migration_risk.timeout_seconds', 5);
            $this->application()->make('migrator')->path($probe);

            $inspection = $this->application()->make(MigrationRiskInspector::class)->inspect(['limit' => 100]);
            $rules = [];

            foreach ($inspection->findings as $finding) {
                $rules[$finding->migration][] = $finding->ruleId . ':' . $finding->severity->value;
            }

            $this->assertContains('MIG002:high', $rules['2099_04_01_000000_beacon_risk_drop_column'] ?? []);
            $this->assertContains('MIG004:high', $rules['2099_04_01_000001_beacon_risk_required_column'] ?? []);
            $this->assertContains('MIG005:medium', $rules['2099_04_01_000002_beacon_risk_unique'] ?? []);
            $this->assertContains('MIG010:low', $rules['2099_04_01_000003_beacon_risk_index'] ?? []);
            $this->assertContains('MIG013:high', $rules['2099_04_01_000004_beacon_risk_backfill'] ?? []);
            $this->assertSame($before, $this->countRows($pdo, 'select count(*) from users'));
            $this->assertFileDoesNotExist($probe . '/ran.txt');
        } finally {
            $this->application()->setBasePath($previous);
            $this->remove($probe);
            config()->set('database.default', 'sqlite');
        }
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

    private function remove(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $files = scandir($directory);

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $directory . '/' . $file;

            if (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
