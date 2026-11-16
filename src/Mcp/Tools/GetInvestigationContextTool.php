<?php

declare(strict_types=1);

namespace Beacon\Mcp\Tools;

use Beacon\Contracts\RequiresCapability;
use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InvalidInvestigationQueryException;
use Beacon\Inspectors\InvestigationContextInspector;
use Beacon\Investigation\InvestigationQuery;
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
 * Exposes normalized AI investigation context over MCP.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetInvestigationContextTool extends Tool implements RequiresCapability
{
    public const NAME = 'beacon.get_investigation_context';

    public const UNAVAILABLE_MESSAGE = 'Investigation context is currently unavailable.';

    protected string $name = self::NAME;

    protected string $title = 'Get investigation context';

    protected string $description = <<<'MARKDOWN'
        Build a normalized investigation context from existing Beacon evidence.

        Supply one or more bounded identifiers: request_id, correlation_id,
        exception_id, fingerprint, endpoint, since, until, severity. Multiple
        identifiers are combined with AND. Primary subject priority is
        request_id, then correlation_id, then exception_id, then fingerprint,
        then endpoint, then the time window. When no scope is supplied a
        default bounded time window is used.

        Distinguishes direct, inferred, and uncorrelated evidence. Does not
        claim a root cause. Does not invent deployment or configuration-change
        history. Bodies, cookies, Authorization headers, query strings, and
        failed-job payloads are never returned.

        Read-only. Requires the "investigation_context" capability. Allowing
        only requests, exceptions, or slow_requests does not grant this tool.
        MARKDOWN;

    public function capability(): Capability
    {
        return InspectionCapability::InvestigationContext->capability();
    }

    public function shouldRegister(InspectorRegistry $inspectors): bool
    {
        return $inspectors->enabled(InvestigationContextInspector::NAME);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'request_id' => $schema->string()->min(8)->max(64)
                ->description('Investigate this request id.'),
            'correlation_id' => $schema->string()->min(8)->max(64)
                ->description('Investigate this correlation id.'),
            'exception_id' => $schema->string()->min(16)->max(16)
                ->description('Investigate this 16-character exception id.'),
            'fingerprint' => $schema->string()->min(32)->max(32)
                ->description('Investigate this 32-character exception fingerprint.'),
            'endpoint' => $schema->string()->min(1)->max(191)
                ->description('Investigate this route name or URI.'),
            'since' => $schema->string()->min(10)->max(35)
                ->description('Include evidence at or after this timestamp.'),
            'until' => $schema->string()->min(10)->max(35)
                ->description('Include evidence at or before this timestamp.'),
            'severity' => $schema->string()->min(3)->max(16)
                ->description('Minimum log severity filter when supported.'),
            'window_minutes' => $schema->integer()->min(1)->max(10080)
                ->description('Default window in minutes when since and until are omitted.'),
            'max_results' => $schema->integer()->min(1)->max(100)
                ->description('Upper bound applied to per-source evidence counts.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'schema_version' => $schema->integer()->required(),
            'investigation_id' => $schema->string()->required(),
            'generated_at' => $schema->string()->required(),
            'completeness' => $schema->string()->required(),
            'truncated' => $schema->boolean()->required(),
            'primary_subject' => $schema->object([])->required(),
            'timeline' => $schema->array()->items($schema->object([]))->required(),
            'leads' => $schema->array()->items($schema->object([]))->required(),
            'missing_evidence' => $schema->array()->items($schema->object([]))->required(),
            'status' => $schema->string()->required(),
            'summary' => $schema->string()->required(),
            'warnings' => $schema->array()->items($schema->string())->required(),
        ];
    }

    public function handle(Beacon $beacon, Principal $caller, Request $request): Response|ResponseFactory
    {
        try {
            return Response::structured($beacon->inspect(
                $caller,
                InvestigationContextInspector::NAME,
                $this->parameters($request),
            )->toArray());
        } catch (AuthorizationException) {
            return Response::error(ExecutionGate::DENIED_MESSAGE);
        } catch (InvalidInvestigationQueryException $exception) {
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
            if (! is_string($name) || ! in_array($name, InvestigationQuery::PARAMETERS, true)) {
                throw InvalidInvestigationQueryException::filter('Investigation rejected an unknown parameter.');
            }

            if ($value !== null && ! is_scalar($value)) {
                throw InvalidInvestigationQueryException::filter('Investigation rejected a non-scalar filter.');
            }

            $parameters[$name] = $value;
        }

        return $parameters;
    }
}
