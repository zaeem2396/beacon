<?php

declare(strict_types=1);

namespace Beacon\Mcp\Tools;

use Beacon\Contracts\RequiresCapability;
use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InvalidRequestQueryException;
use Beacon\Inspectors\SlowRequestSummaryInspector;
use Beacon\Mcp\ExecutionGate;
use Beacon\Requests\RequestQuery;
use Beacon\Requests\SlowRequestQuery;
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
 * Exposes the {@see SlowRequestSummaryInspector} over MCP.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetSlowRequestSummaryTool extends Tool implements RequiresCapability
{
    public const NAME = 'beacon.get_slow_request_summary';

    public const UNAVAILABLE_MESSAGE = 'Slow request summary is currently unavailable.';

    protected string $name = self::NAME;

    protected string $title = 'Get slow request summary';

    protected string $description = <<<'MARKDOWN'
        Summarize endpoint latency for the inspected Beacon request storage window.

        Accepts the same filters as beacon.get_slow_requests. Returns per-endpoint
        sample counts, slow counts, duration metrics, and status breakdowns for
        that window only. Bodies, cookies, Authorization headers, and query strings
        are never stored or returned. Counts are not historical trends.
        p95 needs at least 5 samples. p99 needs at least 20 samples.

        Read-only. Requires the "slow_request_summary" capability. Allowing only
        "requests" or "slow_requests" does not grant this tool.
        MARKDOWN;

    public function capability(): Capability
    {
        return InspectionCapability::SlowRequestSummary->capability();
    }

    public function shouldRegister(InspectorRegistry $inspectors): bool
    {
        return $inspectors->enabled(SlowRequestSummaryInspector::NAME);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'since' => $schema->string()->min(10)->max(35)
                ->description('Include records at or after this timestamp.'),
            'until' => $schema->string()->min(10)->max(35)
                ->description('Include records at or before this timestamp.'),
            'method' => $schema->string()->min(1)->max(16)
                ->description('HTTP method filter.'),
            'route' => $schema->string()->min(1)->max(191)
                ->description('Route name or URI filter.'),
            'status' => $schema->integer()->min(100)->max(599)
                ->description('HTTP status code.'),
            'min_duration_ms' => $schema->integer()->min(0)->max(3_600_000)
                ->description('Minimum duration in milliseconds.'),
            'window_minutes' => $schema->integer()->min(1)->max(10080)
                ->description('Analysis window in minutes when since and until are omitted.'),
            'page' => $schema->integer()->min(1)->max(10000),
            'limit' => $schema->integer()->min(1)->max(RequestQuery::MAX_LIMIT),
            'max_endpoints' => $schema->integer()->min(1)->max(100)
                ->description('Maximum endpoint summaries to return.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'schema_version' => $schema->integer()->required(),
            'endpoints' => $schema->array()->items($schema->object([]))->required(),
            'returned' => $schema->integer()->required(),
            'inspected' => $schema->integer()->required(),
            'slow_count' => $schema->integer()->required(),
            'threshold_ms' => $schema->integer()->required(),
            'window_minutes' => $schema->integer()->required(),
            'minimum_samples' => $schema->integer()->required(),
            'truncated' => $schema->boolean()->required(),
            'status' => $schema->string()->required(),
            'summary' => $schema->string()->required(),
            'warnings' => $schema->array()->items($schema->string())->required(),
        ];
    }

    public function handle(Beacon $beacon, Principal $caller, Request $request): Response|ResponseFactory
    {
        try {
            return Response::structured($beacon->inspect($caller, SlowRequestSummaryInspector::NAME, $this->parameters($request))->toArray());
        } catch (AuthorizationException) {
            return Response::error(ExecutionGate::DENIED_MESSAGE);
        } catch (InvalidRequestQueryException $exception) {
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
            if (! is_string($name) || ! in_array($name, SlowRequestQuery::PARAMETERS, true)) {
                throw InvalidRequestQueryException::filter('Slow request inspection rejected an unknown parameter.');
            }

            if ($value !== null && ! is_scalar($value)) {
                throw InvalidRequestQueryException::filter('Slow request inspection rejected a non-scalar filter.');
            }

            $parameters[$name] = $value;
        }

        return $parameters;
    }
}
