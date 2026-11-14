<?php

declare(strict_types=1);

namespace Beacon\Mcp\Tools;

use Beacon\Contracts\Authorizer;
use Beacon\Contracts\RequiresCapability;
use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InvalidSchemaQueryException;
use Beacon\Inspectors\SchemaSnapshotInspector;
use Beacon\Mcp\ExecutionGate;
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
 * Captures a schema snapshot over MCP.
 *
 * The caller cannot pass SQL, credentials, or a filesystem path.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class CreateSchemaSnapshotTool extends Tool implements RequiresCapability
{
    public const NAME = 'beacon.create_schema_snapshot';

    public const UNAVAILABLE_MESSAGE = 'Schema snapshot capture is currently unavailable.';

    protected string $name = self::NAME;

    protected string $title = 'Create schema snapshot';

    protected string $description = <<<'MARKDOWN'
        Capture a normalized snapshot of an allowed MySQL or MariaDB schema.

        Use this to save structure before comparing environments. The snapshot contains tables,
        views, columns, primary keys, indexes, and foreign keys. It does not contain table rows,
        view definitions, credentials, or hosts. PostgreSQL, SQLite, and SQL Server are recognised
        and not opened.

        connection is an optional Laravel connection name. It defaults to the application default.
        Other names work only when they are listed for schema inspection. There is no SQL mode
        and no filesystem path.

        Read-only. Requires the "schema_snapshot" capability and the "schema" capability.
        Allowing only "schema" does not grant this tool. The schema timeout is BEACON_SCHEMA_TIMEOUT.
        MARKDOWN;

    public function capability(): Capability
    {
        return InspectionCapability::SchemaSnapshot->capability();
    }

    public function shouldRegister(InspectorRegistry $inspectors): bool
    {
        return $inspectors->enabled(SchemaSnapshotInspector::NAME);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'connection' => $schema->string()->min(1)->max(64)
                ->description('Laravel connection name. Defaults to the application default. Not a host or DSN.'),
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
                'name' => $schema->string()->nullable()->required(),
                'driver' => $schema->string()->nullable()->required(),
                'database' => $schema->string()->nullable()->required(),
                'version' => $schema->string()->nullable()->required(),
                'status' => $schema->string()->required(),
            ])->required(),
            'snapshot' => $schema->object([])->required()->description('Versioned snapshot. format_version 1.'),
            'warnings' => $schema->array()->items($schema->string())->required(),
        ];
    }

    public function handle(Beacon $beacon, Principal $caller, Authorizer $authorizer, Request $request): Response|ResponseFactory
    {
        try {
            if (! $authorizer->decide($caller, InspectionCapability::Schema->capability())->allowed) {
                return Response::error(ExecutionGate::DENIED_MESSAGE);
            }

            $parameters = [];
            $connection = $request->get('connection');

            if ($connection !== null) {
                if (! is_string($connection)) {
                    throw InvalidSchemaQueryException::type('connection');
                }

                $parameters['connection'] = $connection;
            }

            return Response::structured($beacon->inspect($caller, SchemaSnapshotInspector::NAME, $parameters)->toArray());
        } catch (AuthorizationException) {
            return Response::error(ExecutionGate::DENIED_MESSAGE);
        } catch (InvalidSchemaQueryException $exception) {
            return Response::error($exception->getMessage());
        } catch (\Throwable) {
            return Response::error(self::UNAVAILABLE_MESSAGE);
        }
    }
}
