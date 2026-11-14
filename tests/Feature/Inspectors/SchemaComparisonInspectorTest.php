<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InvalidSnapshotException;
use Beacon\Inspectors\SchemaComparisonInspector;
use Beacon\Inspectors\SchemaSnapshotInspector;
use Beacon\Tests\Fixtures\RecordingSchemaReader;
use Beacon\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SchemaComparisonInspectorTest extends TestCase
{
    #[Test]
    public function snapshot_capture_requires_schema_and_does_not_read_without_it(): void
    {
        $reader = $this->reader();
        config()->set('beacon.security.allowed_capabilities', ['schema_snapshot']);

        try {
            $this->application()->make(SchemaSnapshotInspector::class)->inspect([]);
            $this->fail('Schema snapshot capture should require the schema capability.');
        } catch (AuthorizationException $exception) {
            $this->assertStringContainsString('schema capability', $exception->getMessage());
        }

        $this->assertSame(0, $reader->calls);
    }

    #[Test]
    public function comparison_parses_json_and_does_not_query_the_database(): void
    {
        $reader = $this->reader();
        config()->set('beacon.security.allowed_capabilities', ['schema_compare']);
        $json = (string) json_encode([
            'format_version' => 1,
            'metadata' => [
                'beacon_version' => 'unknown',
                'laravel_version' => '13.0.0',
                'driver' => 'mysql',
                'server_version' => '8.4.0',
                'database' => 'app',
                'source' => 'snapshot',
                'identifier' => '0123456789abcdef',
                'created_at' => '2026-01-01T00:00:00+00:00',
                'complete' => true,
                'columns_included' => true,
                'indexes_included' => true,
                'references_included' => true,
                'views_included' => true,
            ],
            'objects' => [[
                'name' => 'orders',
                'type' => 'table',
                'columns' => [[
                    'name' => 'email',
                    'type_name' => 'varchar',
                    'normalized_type' => 'varchar',
                    'nullable' => true,
                    'default_present' => false,
                    'default_sensitive' => false,
                    'default_value' => null,
                    'auto_increment' => false,
                    'generated' => false,
                    'generation' => null,
                    'character_length' => 255,
                    'numeric_precision' => null,
                    'numeric_scale' => null,
                    'unsigned' => false,
                    'boolean_like' => false,
                    'json' => false,
                    'position' => 1,
                ]],
                'primary_columns' => [],
                'indexes' => [],
                'references' => [],
            ]],
        ]);
        $other = str_replace('orders', 'legacy', $json);
        $inspection = $this->application()->make(SchemaComparisonInspector::class)->inspect([
            'baseline' => $json,
            'candidate' => $other,
        ]);

        $this->assertSame(0, $reader->calls);
        $this->assertSame('table.orders.removed', $inspection->differences[0]->id);
        $this->assertSame('critical', $inspection->differences[0]->severity->value);
        $this->assertStringNotContainsString('select ', strtolower((string) json_encode($inspection->toArray())));
    }

    #[Test]
    public function sql_text_inside_a_snapshot_is_rejected_and_not_executed(): void
    {
        $reader = $this->reader();
        config()->set('beacon.security.allowed_capabilities', ['schema_compare']);
        $json = '{"format_version":1,"metadata":{"driver":"mysql"},"objects":[{"name":"orders","columns":[{"type_name":"varchar; drop table users"}]}]}';

        try {
            $this->application()->make(SchemaComparisonInspector::class)->inspect([
                'baseline' => $json,
                'candidate' => $json,
            ]);
            $this->fail('Malformed snapshot SQL should be rejected.');
        } catch (InvalidSnapshotException $exception) {
            $this->assertStringNotContainsString('drop table', $exception->getMessage());
        }

        $this->assertSame(0, $reader->calls);
    }

    private function reader(): RecordingSchemaReader
    {
        $reader = new RecordingSchemaReader();
        $this->application()->instance(\Beacon\Schema\SchemaReader::class, $reader);

        return $reader;
    }
}
