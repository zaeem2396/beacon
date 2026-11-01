<?php

declare(strict_types=1);

namespace Beacon\Mcp\Tools;

use Beacon\Contracts\RequiresCapability;
use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Database\ConnectionStatus;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InvalidMigrationQueryException;
use Beacon\Inspectors\MigrationInspector;
use Beacon\Mcp\ExecutionGate;
use Beacon\Migrations\MigrationQuery;
use Beacon\Migrations\MigrationSourceKind;
use Beacon\Migrations\MigrationStatus;
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
 * Exposes the {@see MigrationInspector} over MCP.
 *
 * Arguments are literal filters. The tool does not accept SQL, PHP, or a
 * filesystem path, and failures use a fixed message that does not repeat
 * the rejected input.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetMigrationsTool extends Tool implements RequiresCapability
{
    public const NAME = 'beacon.get_migrations';

    public const UNAVAILABLE_MESSAGE = 'Migration inspection is currently unavailable.';

    protected string $name = self::NAME;

    protected string $title = 'Get migrations';

    protected string $description = <<<'MARKDOWN'
        Compare migration filenames with Laravel's migration repository for an allowed database connection.

        Use this when an investigation needs to know which migrations have been recorded, which files
        are still pending, and which repository rows have no file. It returns metadata only.
        It does not run migrations, rollbacks, or migration PHP. It does not return file contents,
        credentials, hosts, or absolute paths. MySQL, MariaDB, and SQLite are read through Laravel's
        migration repository. PostgreSQL and SQL Server are recognised and not opened.
        A missing migrations table is reported and is not created.

        connection is an optional Laravel connection name. It defaults to the application
        default. Other names work only when they are listed in beacon.migrations.connections.
        name is an optional literal substring of the migration name. status is executed, pending,
        missing_file, duplicate, or unknown. batch is an optional integer from 1 to 1000000.
        path is an optional relative registered migration directory, such as database/migrations.
        It is not an absolute path and it cannot contain "..". page defaults to 1 and must be an
        integer from 1 to 10000. limit defaults to 25 and must be an integer from 1 to 100.

        Pending means the file has no repository row. It does not mean the migration failed.
        Duplicate means the same name exists in more than one registered path, and neither file
        is chosen as the one that ran. filename_at comes from the filename. modified_at is the
        file modification time. Neither is an execution timestamp. The batch number is the only
        execution order the repository stores. The timeout is BEACON_MIGRATIONS_TIMEOUT, from
        1 to 5 seconds, default 2.

        Read-only. Requires the "migrations" capability. The "database" and "schema" capabilities
        do not grant this tool.
        MARKDOWN;

    public function capability(): Capability
    {
        return InspectionCapability::Migrations->capability();
    }

    public function shouldRegister(InspectorRegistry $inspectors): bool
    {
        return $inspectors->enabled(MigrationInspector::NAME);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'connection' => $schema->string()->min(1)->max(64)
                ->description('Laravel connection name. Defaults to the application default. Not a host or DSN.'),
            'name' => $schema->string()->min(1)->max(64)
                ->description('Literal substring of the migration name. Not a regular expression.'),
            'status' => $schema->string()->enum($this->values(MigrationStatus::cases()))
                ->description('executed, pending, missing_file, duplicate, or unknown.'),
            'batch' => $schema->integer()->min(1)->max(MigrationQuery::MAX_BATCH)
                ->description('Repository batch number. Not an execution timestamp.'),
            'path' => $schema->string()->min(1)->max(180)
                ->description('Relative registered migration directory. Not an absolute path.'),
            'page' => $schema->integer()->min(1)->max(MigrationQuery::MAX_PAGE)
                ->description('Page number. Defaults to 1.'),
            'limit' => $schema->integer()->min(1)->max(MigrationQuery::MAX_LIMIT)
                ->description('Page size. Defaults to 25. Larger values are rejected.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'schema_version' => $schema->integer()->required(),
            'connection' => $schema->object([
                'name' => $schema->string()->nullable()->required()->description('Laravel connection name.'),
                'driver' => $schema->string()->nullable()->required(),
                'status' => $schema->string()->enum($this->values(ConnectionStatus::cases()))->required(),
            ])->required(),
            'repository' => $schema->object([
                'available' => $schema->boolean()->required()->description('False when the history table is missing or was not read.'),
                'empty' => $schema->boolean()->required()->description('True only when the table exists and has no rows.'),
                'table' => $schema->string()->nullable()->required()->description('Configured repository table name.'),
            ])->required(),
            'summary' => $schema->object([
                'file_count' => $schema->integer()->required()->description('Filtered migration files, before pagination.'),
                'executed' => $schema->integer()->required(),
                'pending' => $schema->integer()->required(),
                'missing_files' => $schema->integer()->required(),
                'duplicate_names' => $schema->integer()->required(),
                'batch_count' => $schema->integer()->required(),
                'inspected_at' => $schema->string()->required()->description('UTC timestamp.'),
                'summary' => $schema->string()->required(),
            ])->required(),
            'scope' => $schema->object([
                'filtered' => $schema->boolean()->required(),
                'name' => $schema->string()->nullable()->required(),
                'status' => $schema->string()->enum($this->values(MigrationStatus::cases()))->nullable()->required(),
                'batch' => $schema->integer()->nullable()->required(),
                'path' => $schema->string()->nullable()->required()->description('Relative directory. Null when every registered path was included.'),
            ])->required(),
            'pagination' => $schema->object([
                'page' => $schema->integer()->required(),
                'per_page' => $schema->integer()->required(),
                'total' => $schema->integer()->required(),
                'pages' => $schema->integer()->required(),
                'count' => $schema->integer()->required(),
            ])->required(),
            'migrations' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required(),
                'status' => $schema->string()->enum($this->values(MigrationStatus::cases()))->required(),
                'batch' => $schema->integer()->nullable()->required()->description('Repository batch. Null when the name has no row.'),
                'filename' => $schema->string()->nullable()->required(),
                'relative_path' => $schema->string()->nullable()->required()->description('Path relative to the application. Never absolute.'),
                'source' => $schema->string()->enum($this->values(MigrationSourceKind::cases()))->required(),
                'file_exists' => $schema->boolean()->required(),
                'modified_at' => $schema->string()->nullable()->required()->description('File modification time in UTC. Not an execution time.'),
                'filename_at' => $schema->string()->nullable()->required()->description('Timestamp parsed from the filename. Not an execution time.'),
            ]))->required()->description('The requested page, ordered by name.'),
            'batches' => $schema->array()->items($schema->object([
                'batch' => $schema->integer()->required(),
                'count' => $schema->integer()->required()->description('Distinct migration names recorded in the batch.'),
            ]))->required()->description('Batches in the filtered result, not only the current page.'),
            'warnings' => $schema->array()->items($schema->string())->required()->description('Fixed notes. They do not contain paths, SQL, or driver errors.'),
        ];
    }

    public function handle(Beacon $beacon, Principal $caller, Request $request): Response|ResponseFactory
    {
        try {
            return Response::structured($beacon->inspect($caller, MigrationInspector::NAME, $this->parameters($request))->toArray());
        } catch (AuthorizationException) {
            return Response::error(ExecutionGate::DENIED_MESSAGE);
        } catch (InvalidMigrationQueryException $exception) {
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
            if (! is_string($key) || ! in_array($key, MigrationQuery::PARAMETERS, true)) {
                throw InvalidMigrationQueryException::unknownParameter();
            }

            if ($value !== null && ! is_scalar($value)) {
                throw InvalidMigrationQueryException::type($key);
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
