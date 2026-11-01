<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Schema;

use Beacon\Schema\ObjectType;
use Beacon\Schema\SchemaAssembler;
use Beacon\Schema\SchemaQuery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SchemaAssemblerTest extends TestCase
{
    #[Test]
    public function it_normalizes_mysql_metadata_without_rows_or_secret_defaults(): void
    {
        $assembled = (new SchemaAssembler())->assemble(
            ['name' => 'orders', 'type' => ObjectType::Table, 'row_estimate' => 12],
            [
                $this->column('id', 1, 'bigint', 'bigint unsigned', false, null, 'auto_increment'),
                $this->column('total', 2, 'decimal', 'decimal(10,2)', false, '0.00', ''),
                $this->column('note', 3, 'varchar', 'varchar(100)', true, null, ''),
                $this->column('meta', 4, 'json', 'json', true, null, ''),
                $this->column('active', 5, 'tinyint', 'tinyint(1)', false, '1', ''),
                $this->column('password', 6, 'varchar', 'varchar(100)', false, "'schema-password-not-in-output'", ''),
                $this->column('placed_at', 7, 'timestamp', 'timestamp', true, 'CURRENT_TIMESTAMP', 'DEFAULT_GENERATED'),
            ],
            [
                ['name' => 'primary', 'columns' => ['id'], 'unique' => true, 'primary' => true, 'type' => 'btree', 'expression' => false],
                ['name' => 'orders_uuid_unique', 'columns' => ['note'], 'unique' => true, 'primary' => false, 'type' => 'btree', 'expression' => false],
                ['name' => 'unique_email', 'columns' => ['note'], 'unique' => false, 'primary' => false, 'type' => 'btree', 'expression' => false],
            ],
            [[
                'name' => 'items_order_fk',
                'columns' => ['id'],
                'referenced_table' => 'customers',
                'referenced_columns' => ['id'],
                'on_update' => 'cascade',
                'on_delete' => 'set null',
            ]],
            SchemaQuery::from([]),
        );
        $object = $assembled->object;
        $this->assertNotNull($object);
        $encoded = (string) json_encode($object->toArray());

        $this->assertSame(['id'], $object->primaryKey);
        $this->assertTrue($object->estimated);
        $this->assertSame(12, $object->rowEstimate);
        $this->assertSame(7, $object->columnCount);
        $this->assertTrue($object->columns[0]->autoIncrement);
        $this->assertTrue($object->columns[0]->unsigned);
        $this->assertTrue($object->columns[0]->primary);
        $this->assertSame(10, $object->columns[1]->numericPrecision);
        $this->assertSame(2, $object->columns[1]->numericScale);
        $this->assertSame(100, $object->columns[2]->characterLength);
        $this->assertTrue($object->columns[2]->nullable);
        $this->assertTrue($object->columns[3]->json);
        $this->assertTrue($object->columns[4]->booleanLike);
        $this->assertTrue($object->columns[5]->defaultSensitive);
        $this->assertNull($object->columns[5]->defaultValue);
        $this->assertSame('CURRENT_TIMESTAMP', $object->columns[6]->defaultValue);
        $this->assertSame('orders_uuid_unique', $object->uniqueConstraints[0]->name);
        $email = null;

        foreach ($object->indexes as $index) {
            if ($index->name === 'unique_email') {
                $email = $index;
            }
        }

        $this->assertNotNull($email);
        $this->assertFalse($email->unique);
        $this->assertSame('set null', $object->foreignKeys[0]->onDelete->value);
        $this->assertStringNotContainsString('schema-password-not-in-output', $encoded);
    }

    #[Test]
    public function omitted_details_keep_counts_and_a_view_has_no_row_estimate(): void
    {
        $assembled = (new SchemaAssembler())->assemble(
            ['name' => 'order_names', 'type' => ObjectType::View, 'row_estimate' => 99],
            [$this->column('id', 1, 'bigint', 'bigint unsigned', false, null, '')],
            [],
            [],
            SchemaQuery::from(['include_columns' => false]),
        );
        $object = $assembled->object;
        $this->assertNotNull($object);

        $this->assertSame(ObjectType::View, $object->type);
        $this->assertNull($object->rowEstimate);
        $this->assertFalse($object->estimated);
        $this->assertSame(1, $object->columnCount);
        $this->assertSame([], $object->columns);
    }

    #[Test]
    public function a_table_without_a_primary_key_reports_an_empty_key(): void
    {
        $assembled = (new SchemaAssembler())->assemble(
            ['name' => 'labels', 'type' => ObjectType::Table, 'row_estimate' => null],
            [$this->column('name', 1, 'varchar', 'varchar(50)', false, null, '')],
            [],
            [],
            SchemaQuery::from([]),
        );

        $this->assertNotNull($assembled->object);
        $this->assertSame([], $assembled->object->primaryKey);
        $this->assertSame(0, $assembled->object->indexCount);
    }

    /**
     * @return array<string, mixed>
     */
    private function column(string $name, int $position, string $type, string $full, bool $nullable, mixed $default, string $extra): array
    {
        return [
            'name' => $name,
            'ordinal' => $position,
            'type_name' => $type,
            'column_type' => $full,
            'nullable' => $nullable,
            'default' => $default,
            'character_length' => $type === 'varchar' ? (int) filter_var($full, FILTER_SANITIZE_NUMBER_INT) : null,
            'numeric_precision' => $type === 'decimal' ? 10 : null,
            'numeric_scale' => $type === 'decimal' ? 2 : null,
            'extra' => $extra,
            'generation_expression' => null,
        ];
    }
}
