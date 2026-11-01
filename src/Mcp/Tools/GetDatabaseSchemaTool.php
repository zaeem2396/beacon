<?php

declare(strict_types=1);

namespace Beacon\Mcp\Tools;

use Beacon\Contracts\RequiresCapability;
use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Database\ConnectionStatus;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InvalidSchemaQueryException;
use Beacon\Inspectors\SchemaInspector;
use Beacon\Mcp\ExecutionGate;
use Beacon\Schema\ObjectType;
use Beacon\Schema\ReferentialAction;
use Beacon\Schema\SchemaQuery;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;
use Beacon\Security\Principal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Exposes the {@see SchemaInspector} over MCP.
 *
 * Arguments are literal filters. The tool does not accept SQL, and failures
 * use a fixed message that does not repeat the rejected input.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetDatabaseSchemaTool extends Tool implements RequiresCapability
{
    public const NAME = 'beacon.get_database_schema';

    public const UNAVAILABLE_MESSAGE = 'Schema inspection is currently unavailable.';

    protected string $name = self::NAME;

    protected string $title = 'Get database schema';

    protected string $description = <<<'MARKDOWN'
        Inspect tables, views, columns, primary keys, foreign keys, and indexes for an allowed Laravel database connection.

        Use this when an investigation needs the database structure. It returns metadata only.
        It does not return table rows, credentials, hosts, or migration files. MySQL and MariaDB
        are read from information_schema. PostgreSQL, SQLite, and SQL Server are recognised and
        not opened. A caller cannot choose a host, a username, a password, or SQL.

        connection is an optional Laravel connection name. It defaults to the application
        default. Other names work only when they are listed in beacon.schema.connections.
        table is an optional exact object name. include_views, include_columns, include_indexes,
        and include_foreign_keys default to true. page defaults to 1 and must be an integer from
        1 to 10000. limit defaults to 25 and must be an integer from 1 to 50. Values outside
        those ranges are rejected. There is no regular-expression or SQL mode.

        Row counts are metadata estimates when the server provides them, not COUNT(*).
        Column defaults on sensitive names are withheld. Generated expressions are not returned.
        Configured exclusions and a discovery limit bound the result. The timeout is
        BEACON_SCHEMA_TIMEOUT, from 1 to 5 seconds, default 2.

        Read-only. Requires the "schema" capability. The "database" capability does not grant this tool.
        MARKDOWN;

    public function capability(): Capability
    {
        return InspectionCapability::Schema->capability();
    }

    public function shouldRegister(InspectorRegistry $inspectors): bool
    {
        return $inspectors->enabled(SchemaInspector::NAME);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'connection' => $schema->string()->min(1)->max(64)
                ->description('Laravel connection name. Defaults to the application default. Not a host or DSN.'),
            'table' => $schema->string()->min(1)->max(64)
                ->description('Exact table or view name. Not a pattern or SQL expression.'),
            'include_views' => $schema->boolean()
                ->description('Include views. Defaults to true.'),
            'include_columns' => $schema->boolean()
                ->description('Include column details. Counts are still returned when this is false. Defaults to true.'),
            'include_indexes' => $schema->boolean()
                ->description('Include indexes and unique constraints. Defaults to true.'),
            'include_foreign_keys' => $schema->boolean()
                ->description('Include foreign keys. Defaults to true.'),
            'page' => $schema->integer()->min(1)->max(SchemaQuery::MAX_PAGE)
                ->description('Page number. Defaults to 1.'),
            'limit' => $schema->integer()->min(1)->max(SchemaQuery::MAX_LIMIT)
                ->description('Page size. Defaults to 25. Larger values are rejected.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $column = $schema->object([
            'name' => $schema->string()->required(),
            'type_name' => $schema->string()->required()->description('Database data type, such as varchar or bigint.'),
            'column_type' => $schema->string()->nullable()->required()->description('Short type text such as bigint unsigned. Null when it is not safe to disclose.'),
            'nullable' => $schema->boolean()->required(),
            'default_present' => $schema->boolean()->required(),
            'default_sensitive' => $schema->boolean()->required(),
            'default_value' => $schema->string()->nullable()->required()->description('A short literal default. Null when absent or withheld.'),
            'primary' => $schema->boolean()->required(),
            'auto_increment' => $schema->boolean()->required(),
            'generated' => $schema->boolean()->required(),
            'generation' => $schema->string()->nullable()->required()->description('stored, virtual, or null. The expression is not returned.'),
            'character_length' => $schema->integer()->nullable()->required(),
            'numeric_precision' => $schema->integer()->nullable()->required(),
            'numeric_scale' => $schema->integer()->nullable()->required(),
            'unsigned' => $schema->boolean()->required(),
            'json' => $schema->boolean()->required(),
            'boolean_like' => $schema->boolean()->required()->description('True for bool or tinyint(1). This is the database type, not a Laravel cast.'),
            'position' => $schema->integer()->required(),
        ]);
        $index = $schema->object([
            'name' => $schema->string()->required(),
            'columns' => $schema->array()->items($schema->string())->required()->description('Indexed columns in database order.'),
            'unique' => $schema->boolean()->required()->description('From database metadata, not from the index name.'),
            'primary' => $schema->boolean()->required(),
            'type' => $schema->string()->nullable()->required()->description('btree, hash, fulltext, spatial, or null.'),
            'expression' => $schema->boolean()->required()->description('True when a component is not a plain column. The expression text is not returned.'),
        ]);
        $foreignKey = $schema->object([
            'name' => $schema->string()->required(),
            'columns' => $schema->array()->items($schema->string())->required(),
            'referenced_table' => $schema->string()->required(),
            'referenced_columns' => $schema->array()->items($schema->string())->required(),
            'on_update' => $schema->string()->enum($this->values(ReferentialAction::cases()))->required(),
            'on_delete' => $schema->string()->enum($this->values(ReferentialAction::cases()))->required(),
        ]);

        return [
            'schema_version' => $schema->integer()->required(),
            'connection' => $schema->object([
                'name' => $schema->string()->nullable()->required()->description('Laravel connection name.'),
                'driver' => $schema->string()->nullable()->required(),
                'database' => $schema->string()->nullable()->required()->description('Selected schema name when it is a plain identifier.'),
                'version' => $schema->string()->nullable()->required()->description('Short server version, or null.'),
                'status' => $schema->string()->enum($this->values(ConnectionStatus::cases()))->required(),
            ])->required(),
            'summary' => $schema->object([
                'table_count' => $schema->integer()->required()->description('Filtered table count, before pagination.'),
                'view_count' => $schema->integer()->required()->description('Filtered view count, before pagination.'),
                'inspected_at' => $schema->string()->required()->description('UTC timestamp.'),
                'summary' => $schema->string()->required(),
            ])->required(),
            'pagination' => $schema->object([
                'page' => $schema->integer()->required(),
                'per_page' => $schema->integer()->required(),
                'total' => $schema->integer()->required(),
                'pages' => $schema->integer()->required(),
                'count' => $schema->integer()->required(),
            ])->required(),
            'objects' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required(),
                'type' => $schema->string()->enum($this->values(ObjectType::cases()))->required(),
                'row_estimate' => $schema->integer()->nullable()->required()->description('Metadata estimate. Null when the server did not provide one.'),
                'estimated' => $schema->boolean()->required(),
                'column_count' => $schema->integer()->required(),
                'primary_key' => $schema->array()->items($schema->string())->required()->description('Primary key columns in order. Empty when the table has no primary key.'),
                'index_count' => $schema->integer()->required(),
                'foreign_key_count' => $schema->integer()->required(),
                'columns' => $schema->array()->items($column)->required(),
                'foreign_keys' => $schema->array()->items($foreignKey)->required(),
                'indexes' => $schema->array()->items($index)->required(),
                'unique_constraints' => $schema->array()->items($schema->object([
                    'name' => $schema->string()->required(),
                    'columns' => $schema->array()->items($schema->string())->required(),
                ]))->required()->description('Unique indexes that are not the primary key.'),
            ]))->required()->description('The requested page of tables and views, tables first, then views, each by name.'),
            'warnings' => $schema->array()->items($schema->string())->required()->description('Fixed notes. They do not contain driver errors or object names.'),
        ];
    }

    public function handle(Beacon $beacon, Principal $caller, Request $request): Response|ResponseFactory
    {
        try {
            return Response::structured($beacon->inspect($caller, SchemaInspector::NAME, $this->parameters($request))->toArray());
        } catch (AuthorizationException) {
            return Response::error(ExecutionGate::DENIED_MESSAGE);
        } catch (InvalidSchemaQueryException $exception) {
            return Response::error($exception->getMessage());
        } catch (\Throwable) {
            return Response::error(self::UNAVAILABLE_MESSAGE);
        }
    }

    /**
     * @return array<string, scalar|null>
     */
    private function parameters(Request $request): array
    {
        $parameters = [];

        foreach ($request->all() as $key => $value) {
            if (! is_string($key) || ! in_array($key, SchemaQuery::PARAMETERS, true)) {
                throw InvalidSchemaQueryException::unknownParameter();
            }

            if ($value !== null && ! is_scalar($value)) {
                throw InvalidSchemaQueryException::type($key);
            }

            $parameters[$key] = $value;
        }

        return $parameters;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param list<T> $cases
     *
     * @return list<int|string>
     */
    private function values(array $cases): array
    {
        return array_map(static fn (\BackedEnum $case): int|string => $case->value, $cases);
    }
}
