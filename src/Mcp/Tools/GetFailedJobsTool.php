<?php

declare(strict_types=1);

namespace Beacon\Mcp\Tools;

use Beacon\Contracts\RequiresCapability;
use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InvalidLogQueryException;
use Beacon\Inspectors\FailedJobsInspector;
use Beacon\Mcp\ExecutionGate;
use Beacon\QueueIntelligence\FailedJobReader;
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
 * Exposes the {@see FailedJobsInspector} over MCP.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetFailedJobsTool extends Tool implements RequiresCapability
{
    public const NAME = 'beacon.get_failed_jobs';

    public const UNAVAILABLE_MESSAGE = 'Failed job inspection is currently unavailable.';

    /**
     * @var list<string>
     */
    public const PARAMETERS = ['limit'];

    protected string $name = self::NAME;

    protected string $title = 'Get failed jobs';

    protected string $description = <<<'MARKDOWN'
        List failed jobs without payloads or serialized job data.

        limit defaults to 25 and cannot exceed 100. Exception summaries are sanitized.
        Payloads are never selected. Unsupported failed-job drivers are reported and
        not opened. Read-only. Requires the "failed_jobs" capability. Allowing only
        "queue" does not grant this tool.
        MARKDOWN;

    public function capability(): Capability
    {
        return InspectionCapability::FailedJobs->capability();
    }

    public function shouldRegister(InspectorRegistry $inspectors): bool
    {
        return $inspectors->enabled(FailedJobsInspector::NAME);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->min(1)->max(FailedJobReader::MAX_LIMIT)
                ->description('Maximum failed jobs to return.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'schema_version' => $schema->integer()->required(),
            'jobs' => $schema->array()->items($schema->object([]))->required(),
            'returned' => $schema->integer()->required(),
            'total' => $schema->integer()->nullable()->required(),
            'truncated' => $schema->boolean()->required(),
            'driver' => $schema->string()->required(),
            'summary' => $schema->string()->required(),
            'warnings' => $schema->array()->items($schema->string())->required(),
        ];
    }

    public function handle(Beacon $beacon, Principal $caller, Request $request): Response|ResponseFactory
    {
        try {
            return Response::structured($beacon->inspect($caller, FailedJobsInspector::NAME, $this->parameters($request))->toArray());
        } catch (AuthorizationException) {
            return Response::error(ExecutionGate::DENIED_MESSAGE);
        } catch (InvalidLogQueryException $exception) {
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

        foreach ($request->all() as $name => $value) {
            if (! is_string($name) || ! in_array($name, self::PARAMETERS, true)) {
                throw InvalidLogQueryException::filter('Failed job inspection rejected an unknown parameter.');
            }

            if ($value !== null && ! is_scalar($value)) {
                throw InvalidLogQueryException::filter('Failed job inspection rejected a non-scalar filter.');
            }

            $parameters[$name] = $value;
        }

        return $parameters;
    }
}
