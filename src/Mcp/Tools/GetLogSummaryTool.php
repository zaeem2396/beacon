<?php

declare(strict_types=1);

namespace Beacon\Mcp\Tools;

use Beacon\Contracts\RequiresCapability;
use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InvalidLogQueryException;
use Beacon\Inspectors\LogSummaryInspector;
use Beacon\Logs\LogReader;
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
 * Exposes the {@see LogSummaryInspector} over MCP.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetLogSummaryTool extends Tool implements RequiresCapability
{
    public const NAME = 'beacon.get_log_summary';

    public const UNAVAILABLE_MESSAGE = 'Log summary is currently unavailable.';

    /**
     * @var list<string>
     */
    public const PARAMETERS = ['channel', 'limit'];

    protected string $name = self::NAME;

    protected string $title = 'Get log summary';

    protected string $description = <<<'MARKDOWN'
        Summarize supported file-based Laravel log channels.

        channel filters to one configured file channel. limit defaults to 100 and
        cannot exceed 200. Returns counts by level and channel for the inspected
        window. Message bodies are not returned. Remote log drivers are not opened.

        Read-only. Requires the "logs" capability.
        MARKDOWN;

    public function capability(): Capability
    {
        return InspectionCapability::Logs->capability();
    }

    public function shouldRegister(InspectorRegistry $inspectors): bool
    {
        return $inspectors->enabled(LogSummaryInspector::NAME);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'channel' => $schema->string()->min(1)->max(64)
                ->description('Configured file log channel name.'),
            'limit' => $schema->integer()->min(1)->max(LogReader::MAX_LIMIT)
                ->description('Maximum records to summarize.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'schema_version' => $schema->integer()->required(),
            'inspected' => $schema->integer()->required(),
            'error_count' => $schema->integer()->required(),
            'critical_count' => $schema->integer()->required(),
            'by_level' => $schema->object([])->required(),
            'by_channel' => $schema->object([])->required(),
            'window_start' => $schema->string()->nullable()->required(),
            'window_end' => $schema->string()->nullable()->required(),
            'parse_failures' => $schema->integer()->required(),
            'truncated' => $schema->boolean()->required(),
            'summary' => $schema->string()->required(),
            'warnings' => $schema->array()->items($schema->string())->required(),
        ];
    }

    public function handle(Beacon $beacon, Principal $caller, Request $request): Response|ResponseFactory
    {
        try {
            return Response::structured($beacon->inspect($caller, LogSummaryInspector::NAME, $this->parameters($request))->toArray());
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
                throw InvalidLogQueryException::filter('Log summary rejected an unknown parameter.');
            }

            if ($value !== null && ! is_scalar($value)) {
                throw InvalidLogQueryException::filter('Log summary rejected a non-scalar filter.');
            }

            $parameters[$name] = $value;
        }

        return $parameters;
    }
}
