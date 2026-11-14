<?php

declare(strict_types=1);

namespace Beacon\Mcp\Tools;

use Beacon\Contracts\RequiresCapability;
use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InvalidRuntimeQueryException;
use Beacon\Inspectors\QueueInspector;
use Beacon\Mcp\ExecutionGate;
use Beacon\QueueIntelligence\QueueStatus;
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
 * Exposes the {@see QueueInspector} over MCP.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetQueueStatusTool extends Tool implements RequiresCapability
{
    public const NAME = 'beacon.get_queue_status';

    public const UNAVAILABLE_MESSAGE = 'Queue status is currently unavailable.';

    protected string $name = self::NAME;

    protected string $title = 'Get queue status';

    protected string $description = <<<'MARKDOWN'
        Report queue configuration and safely observable backend state.

        Returns configured connections, allowlisted drivers, optional Redis pending
        counts, and a failed-job count when the failed-job repository can be read.
        Worker processes are not inspected. Credentials and hosts are not returned.
        A configured driver is not proof that workers are running.

        Takes no arguments. Read-only. Requires the "queue" capability.
        MARKDOWN;

    public function capability(): Capability
    {
        return InspectionCapability::Queue->capability();
    }

    public function shouldRegister(InspectorRegistry $inspectors): bool
    {
        return $inspectors->enabled(QueueInspector::NAME);
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
            'default_connection' => $schema->string()->nullable()->required(),
            'connections' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required(),
                'driver' => $schema->string()->nullable()->required(),
                'queue_name' => $schema->string()->nullable()->required(),
                'retry_after' => $schema->integer()->nullable()->required(),
                'backend_status' => $schema->string()->enum($this->values(QueueStatus::cases()))->required(),
                'pending' => $schema->integer()->nullable()->required(),
                'note' => $schema->string()->required(),
            ]))->required(),
            'failed_job_count' => $schema->integer()->nullable()->required(),
            'worker_evidence' => $schema->string()->required(),
            'summary' => $schema->string()->required(),
            'warnings' => $schema->array()->items($schema->string())->required(),
        ];
    }

    public function handle(Beacon $beacon, Principal $caller, Request $request): Response|ResponseFactory
    {
        try {
            if ($request->all() !== []) {
                throw new InvalidRuntimeQueryException('Queue inspection does not accept arguments.');
            }

            return Response::structured($beacon->inspect($caller, QueueInspector::NAME)->toArray());
        } catch (AuthorizationException) {
            return Response::error(ExecutionGate::DENIED_MESSAGE);
        } catch (InvalidRuntimeQueryException $exception) {
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
