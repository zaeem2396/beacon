<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Schema;

use Beacon\Exceptions\InvalidSchemaQueryException;
use Beacon\Schema\ColumnDefault;
use Beacon\Schema\MetadataGroups;
use Beacon\Schema\SchemaQuery;
use Beacon\Schema\SchemaSql;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SchemaRulesTest extends TestCase
{
    #[Test]
    public function only_metadata_statements_are_read_only(): void
    {
        $this->assertTrue(SchemaSql::isReadOnly('select table_name from information_schema.tables'));
        $this->assertTrue(SchemaSql::isReadOnly('set session transaction read only'));
        $this->assertFalse(SchemaSql::isReadOnly('insert into orders values (1)'));
        $this->assertFalse(SchemaSql::isReadOnly('update orders set total = 1'));
        $this->assertFalse(SchemaSql::isReadOnly('delete from orders'));
        $this->assertFalse(SchemaSql::isReadOnly('drop table orders'));
        $this->assertFalse(SchemaSql::isReadOnly('alter table orders add note varchar(10)'));
    }

    #[Test]
    public function filters_reject_sql_without_repeating_it(): void
    {
        try {
            SchemaQuery::from(['table' => "orders'; drop table orders"]);
            $this->fail('The table filter was accepted.');
        } catch (InvalidSchemaQueryException $exception) {
            $this->assertStringNotContainsString('drop table', $exception->getMessage());
            $this->assertStringNotContainsString('orders', $exception->getMessage());
        }
    }

    #[Test]
    public function limits_and_unknown_filters_are_rejected(): void
    {
        $this->expectException(InvalidSchemaQueryException::class);

        SchemaQuery::from(['limit' => 51, 'sql' => 'select * from orders']);
    }

    #[Test]
    public function a_sensitive_default_is_withheld(): void
    {
        $default = ColumnDefault::from('password', "'schema-password-not-in-output'");

        $this->assertTrue($default->present);
        $this->assertTrue($default->sensitive);
        $this->assertNull($default->value);
        $this->assertSame('0.00', ColumnDefault::from('total', '0.00')->value);
        $this->assertSame('CURRENT_TIMESTAMP', ColumnDefault::from('placed_at', 'CURRENT_TIMESTAMP')->value);
        $this->assertNull(ColumnDefault::from('note', 'uuid()')->value);
    }

    #[Test]
    public function uniqueness_comes_from_metadata_and_foreign_keys_keep_column_order(): void
    {
        $indexes = MetadataGroups::indexesByTable([
            ['table_name' => 'orders', 'index_name' => 'unique_email', 'seq_in_index' => 1, 'column_name' => 'email', 'non_unique' => 1, 'index_type' => 'BTREE'],
            ['table_name' => 'orders', 'index_name' => 'PRIMARY', 'seq_in_index' => 1, 'column_name' => 'id', 'non_unique' => 0, 'index_type' => 'BTREE'],
            ['table_name' => 'items', 'index_name' => 'PRIMARY', 'seq_in_index' => 2, 'column_name' => 'line_no', 'non_unique' => 0, 'index_type' => 'BTREE'],
            ['table_name' => 'items', 'index_name' => 'PRIMARY', 'seq_in_index' => 1, 'column_name' => 'order_id', 'non_unique' => 0, 'index_type' => 'BTREE'],
        ]);
        $foreignKeys = MetadataGroups::foreignKeysByTable([
            ['table_name' => 'items', 'constraint_name' => 'items_order_fk', 'ordinal_position' => 1, 'column_name' => 'order_id', 'referenced_table_name' => 'orders', 'referenced_column_name' => 'id', 'update_rule' => 'CASCADE', 'delete_rule' => 'CASCADE'],
        ]);

        $email = $this->index($indexes['orders'], 'unique_email');
        $primary = $this->index($indexes['items'], 'primary');

        $this->assertFalse($email['unique']);
        $this->assertFalse($email['primary']);
        $this->assertTrue($this->index($indexes['orders'], 'primary')['unique']);
        $this->assertSame(['order_id', 'line_no'], $primary['columns']);
        $this->assertSame(['order_id'], $foreignKeys['items'][0]['columns']);
        $this->assertSame(['id'], $foreignKeys['items'][0]['referenced_columns']);
        $this->assertSame('cascade', $foreignKeys['items'][0]['on_delete']);
    }

    /**
     * @param list<array{name: string, columns: list<string>, unique: bool, primary: bool, type: string, expression: bool}> $indexes
     *
     * @return array{name: string, columns: list<string>, unique: bool, primary: bool, type: string, expression: bool}
     */
    private function index(array $indexes, string $name): array
    {
        foreach ($indexes as $index) {
            if ($index['name'] === $name) {
                return $index;
            }
        }

        $this->fail('Missing index ' . $name);
    }
}
