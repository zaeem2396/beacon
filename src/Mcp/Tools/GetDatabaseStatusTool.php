<?php

declare(strict_types=1);

namespace Beacon\Mcp\Tools;

use Beacon\Contracts\RequiresCapability;
use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Database\ConnectionStatus;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InvalidDatabaseQueryException;
use Beacon\Inspectors\DatabaseInspector;
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
 * Exposes the {@see DatabaseInspector} over MCP.
 *
 * The tool takes no arguments. It inspects the configured default connection
 * and does not return credentials, hosts, or schema.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetDatabaseStatusTool extends Tool implements RequiresCapability
{
    public const NAME = 'beacon.get_database_status';

    public const UNAVAILABLE_MESSAGE = 'Database status is currently unavailable.';

    protected string $name = self::NAME;

    protected string $title = 'Get database status';

    protected string $description = <<<'MARKDOWN'
        Report the default Laravel database connection with a read-only probe.

        Returns schema_version, connection, driver, status, version, latency_ms, and summary.
        connection is the Laravel connection name, not a host or database name. driver is a
        recognised driver. status is connected, unavailable, unknown, or unsupported.
        version is a short server version such as 8.0.36 or 10.11.6-MariaDB, or null.
        latency_ms is the probe duration in milliseconds, or null when the probe did not
        complete. MySQL, MariaDB, and SQLite are probed with select 1 and a version
        statement. PostgreSQL and SQL Server are identified and not opened. A missing
        sqlite file is not created.

        Takes no arguments. A caller cannot choose a connection, a timeout, or SQL.
        The timeout is BEACON_DATABASE_TIMEOUT, from 1 to 5 seconds, default 2. Does not
        migrate, write, or return credentials, connection strings, hosts, or exception
        text. Does not inspect schema or migrations. Read-only. Requires the "database"
        capability.
        MARKDOWN;

    public function capability(): Capability
    {
        return InspectionCapability::Database->capability();
    }

    public function shouldRegister(InspectorRegistry $inspectors): bool
    {
        return $inspectors->enabled(DatabaseInspector::NAME);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'schema_version' => $schema->integer()->required()
                ->description('Version of this response schema.'),
            'connection' => $schema->string()->nullable()->required()
                ->description('Laravel connection name. Null when the name cannot be read safely.'),
            'driver' => $schema->string()->nullable()->required()
                ->description('Recognised driver. Null when the driver cannot be read safely.'),
            'status' => $schema->string()->enum($this->values(ConnectionStatus::cases()))->required()
                ->description('connected, unavailable, unknown, or unsupported.'),
            'version' => $schema->string()->nullable()->required()
                ->description('Short server version. Null when it was not read.'),
            'latency_ms' => $schema->integer()->nullable()->required()
                ->description('Probe duration in milliseconds. Null when the probe did not complete.'),
            'summary' => $schema->string()->required()
                ->description('Fixed explanation of the status.'),
        ];
    }

    public function handle(Beacon $beacon, Principal $caller, Request $request): Response|ResponseFactory
    {
        try {
            if ($request->all() !== []) {
                throw InvalidDatabaseQueryException::arguments();
            }

            return Response::structured($beacon->inspect($caller, DatabaseInspector::NAME)->toArray());
        } catch (AuthorizationException) {
            return Response::error(ExecutionGate::DENIED_MESSAGE);
        } catch (InvalidDatabaseQueryException $exception) {
            return Response::error($exception->getMessage());
        } catch (\Throwable) {
            return Response::error(self::UNAVAILABLE_MESSAGE);
        }
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
