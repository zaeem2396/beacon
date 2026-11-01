<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Migrations;

use Beacon\Data\MigrationConnection;
use Beacon\Data\MigrationEntry;
use Beacon\Data\MigrationInspection;
use Beacon\Data\MigrationPage;
use Beacon\Data\MigrationRepositoryInfo;
use Beacon\Data\MigrationScope;
use Beacon\Data\MigrationSummary;
use Beacon\Database\ConnectionStatus;
use Beacon\Exceptions\InvalidMigrationQueryException;
use Beacon\Migrations\DiscoveredFile;
use Beacon\Migrations\MigrationCatalog;
use Beacon\Migrations\MigrationName;
use Beacon\Migrations\MigrationQuery;
use Beacon\Migrations\MigrationSourceKind;
use Beacon\Migrations\MigrationStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MigrationCatalogTest extends TestCase
{
    private MigrationCatalog $catalog;

    #[Test]
    public function filters_reject_paths_sql_and_php_without_repeating_them(): void
    {
        foreach ([
            ['path' => '../../etc/passwd'],
            ['name' => 'orders; drop table'],
            ['status' => 'failed'],
            ['batch' => 0],
            ['limit' => 101],
            ['sql' => 'select * from migrations'],
        ] as $parameters) {
            try {
                MigrationQuery::from($parameters);
                $this->fail('A migration filter was accepted.');
            } catch (InvalidMigrationQueryException $exception) {
                $encoded = json_encode($parameters);
                $this->assertIsString($encoded);

                foreach (['passwd', 'drop table', 'select *', 'failed'] as $secret) {
                    if (str_contains($encoded, $secret)) {
                        $this->assertStringNotContainsString($secret, $exception->getMessage());
                    }
                }
            }
        }
    }

    #[Test]
    public function names_are_classified_without_choosing_a_duplicate(): void
    {
        $result = $this->catalog->classify(
            [
                $this->file('2026_01_01_000000_create_users_table', 'database/migrations'),
                $this->file('2026_01_02_000000_create_orders_table', 'database/migrations'),
                $this->file('2026_01_02_000000_create_orders_table', 'database/migrations/tenant'),
                $this->file('2026_02_01_000000_add_order_notes', 'database/migrations'),
            ],
            [
                '2026_01_01_000000_create_users_table' => 1,
                '2026_01_03_000000_removed_widget' => 2,
                '../secret' => 1,
            ],
            true,
            MigrationQuery::from([]),
        );

        $this->assertSame(
            [MigrationStatus::Executed, MigrationStatus::Duplicate, MigrationStatus::Duplicate, MigrationStatus::MissingFile, MigrationStatus::Pending],
            array_map(static fn (MigrationEntry $entry): MigrationStatus => $entry->status, $result->migrations),
        );
        $this->assertSame('2026_01_02_000000_create_orders_table', $result->migrations[1]->name);
        $this->assertSame('database/migrations/tenant/2026_01_02_000000_create_orders_table.php', $result->migrations[2]->relativePath);
        $this->assertNull($result->migrations[1]->batch);
        $this->assertSame(2, $result->migrations[3]->batch);
        $this->assertFalse($result->migrations[3]->fileExists);
        $this->assertSame(1, $result->migrations[0]->batch);
        $this->assertNull($result->migrations[4]->batch);
        $this->assertSame(4, $result->fileCount);
        $this->assertSame(1, $result->executed);
        $this->assertSame(1, $result->pending);
        $this->assertSame(1, $result->missingFiles);
        $this->assertSame(1, $result->duplicateNames);
        $this->assertTrue($result->withheld);
        $this->assertSame([1, 2], array_map(static fn ($batch) => $batch->batch, $result->batches));
        $this->assertSame([1, 1], array_map(static fn ($batch) => $batch->count, $result->batches));
    }

    #[Test]
    public function a_repository_row_does_not_choose_one_duplicate_file(): void
    {
        $result = $this->catalog->classify(
            [
                $this->file('2026_01_02_000000_create_orders_table', 'database/migrations'),
                $this->file('2026_01_02_000000_create_orders_table', 'database/migrations/tenant'),
            ],
            ['2026_01_02_000000_create_orders_table' => 3],
            true,
            MigrationQuery::from([]),
        );

        $this->assertSame(MigrationStatus::Duplicate, $result->migrations[0]->status);
        $this->assertSame(MigrationStatus::Duplicate, $result->migrations[1]->status);
        $this->assertSame(3, $result->migrations[0]->batch);
        $this->assertSame(3, $result->migrations[1]->batch);
        $this->assertSame(0, $result->executed);
        $this->assertSame(1, $result->batches[0]->count);
    }

    #[Test]
    public function a_missing_repository_is_not_described_as_pending(): void
    {
        $result = $this->catalog->classify(
            [$this->file('2026_01_01_000000_create_users_table', 'database/migrations')],
            [],
            false,
            MigrationQuery::from([]),
        );

        $this->assertSame(MigrationStatus::Unknown, $result->migrations[0]->status);
        $this->assertSame(0, $result->pending);
        $this->assertSame('2026-01-01T00:00:00+00:00', $result->migrations[0]->filenameAt);
        $this->assertNotSame($result->migrations[0]->filenameAt, $result->migrations[0]->modifiedAt);
    }

    #[Test]
    public function filters_and_pagination_inputs_narrow_the_counts(): void
    {
        $result = $this->catalog->classify(
            [
                $this->file('2026_01_01_000000_create_users_table', 'database/migrations'),
                $this->file('2026_01_02_000000_create_orders_table', 'database/migrations'),
            ],
            ['2026_01_01_000000_create_users_table' => 1],
            true,
            MigrationQuery::from(['name' => 'orders', 'status' => 'pending']),
        );

        $this->assertCount(1, $result->migrations);
        $this->assertSame(0, $result->executed);
        $this->assertSame(1, $result->pending);
        $this->assertTrue(MigrationQuery::from(['name' => 'orders'])->filtered());
        $this->assertSame(2, MigrationQuery::from(['page' => 2, 'limit' => 1])->page);
    }

    #[Test]
    public function an_empty_repository_is_distinct_in_the_serialized_report(): void
    {
        $inspection = new MigrationInspection(
            new MigrationConnection('mysql', 'mysql', ConnectionStatus::Connected),
            new MigrationRepositoryInfo(true, true, 'migrations'),
            new MigrationSummary(0, 0, 0, 0, 0, 0, '2026-11-01T09:00:00+00:00', 'No migration files or repository records were found.'),
            new MigrationScope(false, null, null, null, null),
            new MigrationPage(1, 25, 0, 0, 0),
            [],
            [],
            [],
        );
        $missing = new MigrationRepositoryInfo(false, false, 'migrations');

        $this->assertSame(
            ['schema_version', 'connection', 'repository', 'summary', 'scope', 'pagination', 'migrations', 'batches', 'warnings'],
            array_keys($inspection->toArray()),
        );
        $this->assertTrue($inspection->repository->empty);
        $this->assertFalse($missing->empty);
        $this->assertSame('2026-01-01T00:00:00+00:00', MigrationName::filenameAt('2026_01_01_000000_create_users_table'));
        $this->assertNull(MigrationName::filenameAt('2026_13_40_000000_not_a_date'));
    }

    protected function setUp(): void
    {
        $this->catalog = new MigrationCatalog();
    }

    private function file(string $name, string $directory): DiscoveredFile
    {
        return new DiscoveredFile(
            $name,
            $directory . '/' . $name . '.php',
            $directory,
            $directory === 'database/migrations' ? MigrationSourceKind::Application : MigrationSourceKind::Registered,
            '2026-10-02T03:04:05+00:00',
            MigrationName::filenameAt($name),
        );
    }
}
