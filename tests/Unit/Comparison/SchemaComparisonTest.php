<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Comparison;

use Beacon\Comparison\ChangeType;
use Beacon\Comparison\SchemaComparator;
use Beacon\Comparison\SnapshotColumn;
use Beacon\Comparison\SnapshotDocument;
use Beacon\Comparison\SnapshotForeignKey;
use Beacon\Comparison\SnapshotIndex;
use Beacon\Comparison\SnapshotLoader;
use Beacon\Comparison\SnapshotMetadata;
use Beacon\Comparison\SnapshotObject;
use Beacon\Exceptions\InvalidSnapshotException;
use Beacon\Schema\ObjectType;
use Beacon\Security\KeyPatternRedactor;
use Beacon\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SchemaComparisonTest extends TestCase
{
    #[Test]
    public function identical_structure_ignores_snapshot_metadata(): void
    {
        $table = $this->table('orders', [$this->varchar('email', 1)]);
        $draft = (new SchemaComparator())->compare(
            $this->document([$table], 'local_db', '2026-01-01T00:00:00+00:00'),
            $this->document([$table], 'prod_db', '2026-02-01T00:00:00+00:00'),
            false,
        );

        $this->assertSame([], $draft->differences);
        $this->assertTrue($draft->complete);
        $this->assertSame(['View definitions were not compared.'], $draft->limitations);
    }

    #[Test]
    public function structural_changes_keep_severity_and_do_not_invent_renames(): void
    {
        $before = $this->document([
            $this->table('orders', [
                $this->integer('id', 1, true),
                $this->varchar('email', 2, 255, false),
                $this->decimal('total', 3, 10, 2),
            ], ['id'], [
                new SnapshotIndex('orders_email_unique', ['email'], true, 'btree', false),
            ], [
                new SnapshotForeignKey('orders_user_id_foreign', ['user_id'], 'users', ['id'], 'restrict', 'restrict'),
            ]),
            $this->table('legacy', [$this->integer('id', 1, true)], ['id']),
        ]);
        $after = $this->document([
            $this->table('orders', [
                $this->integer('id', 1, true),
                $this->varchar('note', 2, 255, true),
                $this->decimal('total', 3, 8, 2),
                $this->varchar('state', 4, 20, false, false),
            ], ['id'], [
                new SnapshotIndex('orders_state_index', ['state'], false, 'btree', false),
            ], [
                new SnapshotForeignKey('orders_user_id_foreign', ['user_id'], 'users', ['id'], 'restrict', 'cascade'),
            ]),
            $this->table('audit', [$this->integer('id', 1, true)], ['id']),
        ]);

        $draft = (new SchemaComparator())->compare($before, $after, false);
        $ids = array_map(static fn ($difference): string => $difference->id . ':' . $difference->severity->value . ':' . $difference->confidence->value, $draft->differences);

        $this->assertContains('table.legacy.removed:critical:high', $ids);
        $this->assertContains('table.audit.added:informational:high', $ids);
        $this->assertContains('column.orders.email.removed:high:high', $ids);
        $this->assertContains('column.orders.note.added:low:high', $ids);
        $this->assertContains('column.orders.state.added:high:high', $ids);
        $this->assertContains('column.orders.total.modified.numeric_precision:high:high', $ids);
        $this->assertContains('index.orders.orders_email_unique.removed:medium:high', $ids);
        $this->assertContains('index.orders.orders_state_index.added:low:high', $ids);
        $this->assertContains('foreign_key.orders.orders_user_id_foreign.modified.on_delete:medium:high', $ids);
        $this->assertNotContains('column.orders.email.renamed.name:medium:medium', $ids);

        $renamed = (new SchemaComparator())->compare($before, $after, true);
        $rename = null;

        foreach ($renamed->differences as $difference) {
            if ($difference->change === ChangeType::Renamed && $difference->heuristic) {
                $rename = $difference;
            }
        }

        $this->assertNotNull($rename);
        $this->assertSame('medium', $rename->confidence->value);
        $this->assertStringContainsString('heuristic', $rename->explanation);
    }

    #[Test]
    public function incomplete_column_metadata_is_not_reported_as_column_changes(): void
    {
        $full = $this->document([$this->table('orders', [$this->varchar('email', 1)])]);
        $partial = $this->document([$this->table('orders', [])], columnsIncluded: false);
        $draft = (new SchemaComparator())->compare($full, $partial, false);

        $this->assertFalse($draft->complete);
        $this->assertSame([], array_filter(
            $draft->differences,
            static fn ($difference): bool => $difference->category->value === 'column',
        ));
        $this->assertContains('Column metadata is incomplete, so columns were not compared.', $draft->limitations);
    }

    #[Test]
    public function empty_snapshots_have_no_structural_differences(): void
    {
        $draft = (new SchemaComparator())->compare($this->document([]), $this->document([]), false);

        $this->assertSame([], $draft->differences);
        $this->assertTrue($draft->complete);
    }

    #[Test]
    public function a_driver_label_is_metadata_and_structure_is_still_compared(): void
    {
        $table = $this->table('orders', [$this->varchar('email', 1)]);
        $draft = (new SchemaComparator())->compare(
            $this->document([$table], driver: 'mysql'),
            $this->document([$table], driver: 'mariadb'),
            false,
        );

        $this->assertCount(1, $draft->differences);
        $this->assertSame('metadata.drivers.modified.driver', $draft->differences[0]->id);
        $this->assertSame('informational', $draft->differences[0]->severity->value);
        $this->assertTrue($draft->complete);
    }

    #[Test]
    public function snapshots_round_trip_through_json_and_reject_unsafe_input(): void
    {
        $document = $this->document([$this->table('orders', [$this->integer('id', 1, true)], ['id'], [], [
            new SnapshotForeignKey('orders_user_foreign', ['user_id'], 'users', ['id'], 'restrict', 'cascade'),
        ])]);
        $loaded = (new SnapshotLoader())->load((string) json_encode($document->toArray()));
        $redacted = (new KeyPatternRedactor([]))->redact($document->toArray());

        $this->assertSame($document->metadata->identifier, $loaded->metadata->identifier);
        $this->assertSame('orders', $loaded->objects[0]->name);
        $this->assertIsArray($redacted['objects']);
        $object = $redacted['objects'][0] ?? null;
        $this->assertIsArray($object);
        $this->assertSame(['id'], $object['primary_columns']);
        $this->assertIsArray($object['references']);

        $loader = new SnapshotLoader();

        foreach ([
            'O:8:"stdClass":0:{}',
            '{',
            (string) json_encode(['format_version' => 2, 'metadata' => [], 'objects' => []]),
            (string) json_encode(['format_version' => 1, 'metadata' => ['driver' => 'pgsql'], 'objects' => []]),
            str_repeat('a', SnapshotLoader::MAX_BYTES + 1),
        ] as $payload) {
            try {
                $loader->load($payload);
                $this->fail('Unsafe snapshot input should be rejected.');
            } catch (InvalidSnapshotException $exception) {
                $this->assertStringNotContainsString('stdClass', $exception->getMessage());
                $this->assertStringNotContainsString('pgsql', $exception->getMessage());
            }
        }
    }

    /**
     * @param list<SnapshotColumn> $columns
     * @param list<string> $primaryKey
     * @param list<SnapshotIndex> $indexes
     * @param list<SnapshotForeignKey> $foreignKeys
     */
    private function table(string $name, array $columns, array $primaryKey = [], array $indexes = [], array $foreignKeys = []): SnapshotObject
    {
        return new SnapshotObject($name, ObjectType::Table, $columns, $primaryKey, $indexes, $foreignKeys);
    }

    private function varchar(string $name, int $position, int $length = 255, bool $nullable = true, bool $defaultPresent = false): SnapshotColumn
    {
        return $this->column($name, 'varchar', $position, $nullable, $length, null, null, false, $defaultPresent);
    }

    private function integer(string $name, int $position, bool $autoIncrement): SnapshotColumn
    {
        return $this->column($name, 'bigint', $position, false, null, null, null, $autoIncrement, false, true);
    }

    private function decimal(string $name, int $position, int $precision, int $scale): SnapshotColumn
    {
        return $this->column($name, 'decimal', $position, false, null, $precision, $scale, false, false);
    }

    private function column(
        string $name,
        string $type,
        int $position,
        bool $nullable,
        ?int $length,
        ?int $precision,
        ?int $scale,
        bool $autoIncrement,
        bool $defaultPresent,
        bool $unsigned = false,
    ): SnapshotColumn {
        return new SnapshotColumn(
            $name,
            $type,
            SnapshotColumn::normalize($type, false, false),
            $nullable,
            $defaultPresent,
            false,
            $defaultPresent ? 'open' : null,
            $autoIncrement,
            false,
            null,
            $length,
            $precision,
            $scale,
            $unsigned,
            false,
            false,
            $position,
        );
    }

    /**
     * @param list<SnapshotObject> $objects
     */
    private function document(
        array $objects,
        string $database = 'app',
        string $createdAt = '2026-01-01T00:00:00+00:00',
        string $driver = 'mysql',
        bool $columnsIncluded = true,
    ): SnapshotDocument {
        return new SnapshotDocument(new SnapshotMetadata(
            'unknown',
            '13.0.0',
            $driver,
            '8.4.0',
            $database,
            'snapshot',
            SnapshotDocument::identifier($objects),
            $createdAt,
            true,
            $columnsIncluded,
            true,
            true,
            true,
        ), $objects);
    }
}
