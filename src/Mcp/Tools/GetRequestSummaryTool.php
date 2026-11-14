<?php

declare(strict_types=1);

namespace Beacon\Mcp\Tools;

use Beacon\Contracts\RequiresCapability;
use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InvalidRequestQueryException;
use Beacon\Inspectors\RequestSummaryInspector;
use Beacon\Mcp\ExecutionGate;
use Beacon\Requests\RequestQuery;
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
 * Exposes the {@see RequestSummaryInspector} over MCP.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetRequestSummaryTool extends Tool implements RequiresCapability
{
    public const NAME = 'beacon.get_request_summary';

    public const UNAVAILABLE_MESSAGE = 'Request summary is currently unavailable.';

    protected string $name = self::NAME;

    protected string $title = 'Get request summary';

    protected string $description = <<<'MARKDOWN'
        Summarize HTTP request metrics for the inspected Beacon storage window.

        Accepts the same filters as beacon.get_recent_requests. Returns counts by
        status, method, and route, plus duration metrics for that window only.
        Bodies, cookies, Authorization headers, and query strings are never stored
        or returned. Counts are not historical trends.

        Read-only. Requires the "request_summary" capability. Allowing only
        "requests" does not grant this tool.
        MARKDOWN;

    public function capability(): Capability
    {
        return InspectionCapability::RequestSummary->capability();
    }

    public function shouldRegister(InspectorRegistry $inspectors): bool
    {
        return $inspectors->enabled(RequestSummaryInspector::NAME);
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
            'had_exception' => $schema->boolean()
                ->description('Filter by exception outcome.'),
            'correlation_id' => $schema->string()->min(8)->max(64)
                ->description('Request or correlation identifier.'),
            'page' => $schema->integer()->min(1)->max(10000),
            'limit' => $schema->integer()->min(1)->max(RequestQuery::MAX_LIMIT),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'schema_version' => $schema->integer()->required(),
            'summary' => $schema->object([])->required(),
        ];
    }

    public function handle(Beacon $beacon, Principal $caller, Request $request): Response|ResponseFactory
    {
        try {
            return Response::structured($beacon->inspect($caller, RequestSummaryInspector::NAME, $this->parameters($request))->toArray());
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
            if (! is_string($name) || ! in_array($name, RequestQuery::PARAMETERS, true)) {
                throw InvalidRequestQueryException::filter('Request inspection rejected an unknown parameter.');
            }

            if ($value !== null && ! is_scalar($value)) {
                throw InvalidRequestQueryException::filter('Request inspection rejected a non-scalar filter.');
            }

            $parameters[$name] = $value;
        }

        return $parameters;
    }
}
