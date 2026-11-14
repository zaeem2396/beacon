<?php

declare(strict_types=1);

namespace Beacon\Mcp\Tools;

use Beacon\Contracts\RequiresCapability;
use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InvalidRuntimeQueryException;
use Beacon\Inspectors\SchedulerInspector;
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
 * Exposes the {@see SchedulerInspector} over MCP.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetSchedulerStatusTool extends Tool implements RequiresCapability
{
    public const NAME = 'beacon.get_scheduler_status';

    public const UNAVAILABLE_MESSAGE = 'Scheduler status is currently unavailable.';

    protected string $name = self::NAME;

    protected string $title = 'Get scheduler status';

    protected string $description = <<<'MARKDOWN'
        List registered scheduled task definitions without executing them.

        Returns description, cron expression, timezone, overlap and background flags,
        environments, and a fixed note that execution history was not available.
        Tasks are not run. Absolute paths and credentials are not returned.

        Takes no arguments. Read-only. Requires the "scheduler" capability.
        MARKDOWN;

    public function capability(): Capability
    {
        return InspectionCapability::Scheduler->capability();
    }

    public function shouldRegister(InspectorRegistry $inspectors): bool
    {
        return $inspectors->enabled(SchedulerInspector::NAME);
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
            'tasks' => $schema->array()->items($schema->object([]))->required(),
            'returned' => $schema->integer()->required(),
            'summary' => $schema->string()->required(),
            'warnings' => $schema->array()->items($schema->string())->required(),
        ];
    }

    public function handle(Beacon $beacon, Principal $caller, Request $request): Response|ResponseFactory
    {
        try {
            if ($request->all() !== []) {
                throw new InvalidRuntimeQueryException('Scheduler inspection does not accept arguments.');
            }

            return Response::structured($beacon->inspect($caller, SchedulerInspector::NAME)->toArray());
        } catch (AuthorizationException) {
            return Response::error(ExecutionGate::DENIED_MESSAGE);
        } catch (InvalidRuntimeQueryException $exception) {
            return Response::error($exception->getMessage());
        } catch (\Throwable) {
            return Response::error(self::UNAVAILABLE_MESSAGE);
        }
    }
}
