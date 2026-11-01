<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Core\Beacon;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InvalidRiskQueryException;
use Beacon\Inspectors\MigrationRiskInspector;
use Beacon\Migrations\MigrationHistory;
use Beacon\Security\Principal;
use Beacon\Tests\Fixtures\RecordingMigrationHistory;
use Beacon\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class MigrationRiskInspectorTest extends TestCase
{
    #[Test]
    public function pending_migrations_are_analyzed_without_executing_php(): void
    {
        $history = new RecordingMigrationHistory();
        $this->application()->instance(MigrationHistory::class, $history);
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.driver', 'mysql');
        config()->set('database.connections.mysql.password', 'migration-password-not-in-output');
        config()->set('beacon.security.allowed_capabilities', ['migration_risk']);
        $directory = $this->application()->databasePath('migrations');
        $drop = $directory . '/2099_01_01_000000_beacon_risk_drop.php';
        $included = $directory . '/included-risk.php';
        $marker = $directory . '/ran.txt';

        try {
            file_put_contents($included, <<<'PHP'
                <?php

                file_put_contents(__DIR__ . '/ran.txt', 'migration-php-was-read');
                PHP);
            file_put_contents($drop, <<<'PHP'
                <?php

                file_put_contents(__DIR__ . '/ran.txt', 'migration-php-was-read');

                class BeaconRiskSideEffectProbe
                {
                    public function __construct()
                    {
                        file_put_contents(__DIR__ . '/ran.txt', 'migration-php-was-read');
                    }

                    public function up(): void
                    {
                        file_put_contents(__DIR__ . '/ran.txt', 'migration-php-was-read');
                        include __DIR__ . '/included-risk.php';
                        Schema::dropIfExists('orders');
                    }

                    public function down(): void
                    {
                        file_put_contents(__DIR__ . '/ran.txt', 'migration-php-was-read');
                    }
                }
                PHP);
            $hash = md5_file($drop);

            $inspection = $this->application()->make(MigrationRiskInspector::class)->inspect();
            $encoded = (string) json_encode($inspection->toArray());
            $rules = [];

            foreach ($inspection->findings as $finding) {
                if ($finding->migration === '2099_01_01_000000_beacon_risk_drop') {
                    $rules[] = $finding->ruleId;
                }
            }

            $this->assertContains('MIG001', $rules);
            $this->assertFalse($inspection->scope->enriched);
            $this->assertSame(1, $history->calls);
            $this->assertFileDoesNotExist($marker);
            $this->assertFalse(class_exists('BeaconRiskSideEffectProbe', false));
            $this->assertSame($hash, md5_file($drop));
            $this->assertStringNotContainsString('migration-password-not-in-output', $encoded);
            $this->assertStringNotContainsString('migration-php-was-read', $encoded);
            $messages = array_map(static fn ($limitation): string => $limitation->message, $inspection->limitations);
            $this->assertContains('A migration contains include or require. Those files were not loaded.', $messages);
        } finally {
            @unlink($drop);
            @unlink($included);
            @unlink($marker);
        }
    }

    #[Test]
    public function a_denied_caller_does_not_read_migration_source(): void
    {
        $history = new RecordingMigrationHistory();
        $this->application()->instance(MigrationHistory::class, $history);
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.driver', 'mysql');
        config()->set('beacon.security.allowed_capabilities', ['migrations', 'schema', 'database']);
        $path = $this->application()->databasePath('migrations') . '/2099_01_01_000001_beacon_risk_denied.php';
        $marker = $this->application()->databasePath('migrations') . '/ran.txt';

        try {
            file_put_contents($path, <<<'PHP'
                <?php

                file_put_contents(__DIR__ . '/ran.txt', 'migration-php-was-read');

                class BeaconRiskDeniedProbe
                {
                    public function up(): void
                    {
                        file_put_contents(__DIR__ . '/ran.txt', 'migration-php-was-read');
                        Schema::dropIfExists('orders');
                    }
                }
                PHP);

            try {
                $this->application()->make(Beacon::class)->inspect(Principal::local(), MigrationRiskInspector::NAME);
                $this->fail('The migration_risk capability should be required.');
            } catch (AuthorizationException) {
                $this->assertSame(0, $history->calls);
                $this->assertFileDoesNotExist($marker);
                $this->assertFalse(class_exists('BeaconRiskDeniedProbe', false));
            }
        } finally {
            @unlink($path);
            @unlink($marker);
        }
    }

    #[Test]
    public function an_unparseable_migration_is_not_treated_as_safe(): void
    {
        $this->application()->instance(MigrationHistory::class, new RecordingMigrationHistory());
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.driver', 'mysql');
        $path = $this->application()->databasePath('migrations') . '/2099_01_01_000002_beacon_risk_broken.php';

        try {
            file_put_contents($path, "<?php\nclass Broken {\npublic function up() { Schema::dropIfExists('orders') }\n}\n");
            $inspection = $this->application()->make(MigrationRiskInspector::class)->inspect(['migration' => '2099_01_01_000002_beacon_risk_broken']);
            $messages = array_map(static fn ($limitation): string => $limitation->message, $inspection->limitations);

            $this->assertSame([], $inspection->findings);
            $this->assertContains('The migration source could not be parsed. It was not treated as safe.', $messages);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function severity_and_executed_filters_bound_the_report(): void
    {
        $history = new RecordingMigrationHistory();
        $history->batches = ['2099_01_01_000003_beacon_risk_executed' => 1];
        $this->application()->instance(MigrationHistory::class, $history);
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.driver', 'mysql');
        $path = $this->application()->databasePath('migrations') . '/2099_01_01_000003_beacon_risk_executed.php';

        try {
            file_put_contents($path, <<<'PHP'
                <?php

                class BeaconRiskExecutedProbe
                {
                    public function up(): void
                    {
                        Schema::table('orders', function (Blueprint $table) {
                            $table->index('email');
                            $table->dropColumn('legacy');
                        });
                    }

                    public function down(): void
                    {
                    }
                }
                PHP);
            $pendingOnly = $this->application()->make(MigrationRiskInspector::class)->inspect();
            $pendingNames = [];

            foreach ($pendingOnly->findings as $finding) {
                $pendingNames[] = $finding->migration;
            }

            $this->assertNotContains('2099_01_01_000003_beacon_risk_executed', $pendingNames);

            $executed = $this->application()->make(MigrationRiskInspector::class)->inspect([
                'migration' => '2099_01_01_000003_beacon_risk_executed',
                'severity' => 'high',
            ]);
            $this->assertSame(1, $executed->summary->analyzed);
            $this->assertGreaterThan(0, $executed->summary->high);
            $this->assertSame(0, $executed->summary->low);

            foreach ($executed->findings as $finding) {
                $this->assertContains($finding->severity->value, ['high', 'critical']);
                $this->assertSame('executed', $finding->migrationStatus->value);
            }
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function a_traversal_path_is_rejected_before_source_is_read(): void
    {
        $this->application()->instance(MigrationHistory::class, new RecordingMigrationHistory());
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.driver', 'mysql');

        $this->expectException(InvalidRiskQueryException::class);
        $this->application()->make(MigrationRiskInspector::class)->inspect(['path' => '../secret-risk-path']);
    }
}
