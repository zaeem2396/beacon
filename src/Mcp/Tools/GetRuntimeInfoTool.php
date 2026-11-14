<?php

declare(strict_types=1);

namespace Beacon\Mcp\Tools;

use Beacon\Contracts\RequiresCapability;
use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InvalidRuntimeQueryException;
use Beacon\Inspectors\RuntimeInspector;
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
 * Exposes the {@see RuntimeInspector} over MCP.
 *
 * The tool takes no arguments. It reports allowlisted PHP and Laravel runtime
 * facts and does not return credentials or hostnames.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetRuntimeInfoTool extends Tool implements RequiresCapability
{
    public const NAME = 'beacon.get_runtime_info';

    public const UNAVAILABLE_MESSAGE = 'Runtime information is currently unavailable.';

    protected string $name = self::NAME;

    protected string $title = 'Get runtime information';

    protected string $description = <<<'MARKDOWN'
        Report allowlisted PHP and Laravel runtime facts for the current process.

        Returns schema_version, php, laravel, drivers, summary, and beacon_version.
        php includes version, sapi, os, memory_limit, and a fixed extension presence
        list. laravel includes version, environment, debug, timezone, locale, booted,
        and maintenance_mode. drivers are configured cache, session, queue, filesystem,
        logging, and database names or drivers when they match an allowlist.
        Configured drivers are not proof that a backend is reachable.

        Takes no arguments. Does not return credentials, hosts, absolute paths, or
        environment variable values. Read-only. Requires the "runtime" capability.
        MARKDOWN;

    public function capability(): Capability
    {
        return InspectionCapability::Runtime->capability();
    }

    public function shouldRegister(InspectorRegistry $inspectors): bool
    {
        return $inspectors->enabled(RuntimeInspector::NAME);
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
            'schema_version' => $schema->integer()->required(),
            'php' => $schema->object([])->required(),
            'laravel' => $schema->object([])->required(),
            'drivers' => $schema->object([])->required(),
            'summary' => $schema->string()->required(),
            'beacon_version' => $schema->string()->nullable()->required(),
        ];
    }

    public function handle(Beacon $beacon, Principal $caller, Request $request): Response|ResponseFactory
    {
        try {
            if ($request->all() !== []) {
                throw new InvalidRuntimeQueryException('Runtime inspection does not accept arguments.');
            }

            return Response::structured($beacon->inspect($caller, RuntimeInspector::NAME)->toArray());
        } catch (AuthorizationException) {
            return Response::error(ExecutionGate::DENIED_MESSAGE);
        } catch (InvalidRuntimeQueryException $exception) {
            return Response::error($exception->getMessage());
        } catch (\Throwable) {
            return Response::error(self::UNAVAILABLE_MESSAGE);
        }
    }
}
