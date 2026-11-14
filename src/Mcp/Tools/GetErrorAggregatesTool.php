<?php

declare(strict_types=1);

namespace Beacon\Mcp\Tools;

use Beacon\Contracts\RequiresCapability;
use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InvalidLogQueryException;
use Beacon\Inspectors\ErrorAggregateInspector;
use Beacon\Logs\AggregateQuery;
use Beacon\Logs\ExceptionCategory;
use Beacon\Logs\LogLevel;
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
 * Exposes the {@see ErrorAggregateInspector} over MCP.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetErrorAggregatesTool extends Tool implements RequiresCapability
{
    public const NAME = 'beacon.get_error_aggregates';

    public const UNAVAILABLE_MESSAGE = 'Error aggregation is currently unavailable.';

    protected string $name = self::NAME;

    protected string $title = 'Get error aggregates';

    protected string $description = <<<'MARKDOWN'
        Group recent exceptions by fingerprint for the inspected log window.

        channel, class, severity, category, since, and until filter the source
        exceptions. min_count drops groups below that occurrence count. sort is
        occurrences or last_seen. page defaults to 1. limit defaults to 25 and
        cannot exceed 100.

        Counts are for the inspected window only. They are not historical trends.
        Messages are sanitized. Read-only. Requires the "error_aggregate" capability.
        Allowing only "exceptions" does not grant this tool.
        MARKDOWN;

    public function capability(): Capability
    {
        return InspectionCapability::ErrorAggregate->capability();
    }

    public function shouldRegister(InspectorRegistry $inspectors): bool
    {
        return $inspectors->enabled(ErrorAggregateInspector::NAME);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'channel' => $schema->string()->min(1)->max(64),
            'class' => $schema->string()->min(1)->max(191),
            'severity' => $schema->string()->enum($this->values(LogLevel::cases())),
            'category' => $schema->string()->enum($this->values(ExceptionCategory::cases())),
            'since' => $schema->string()->min(10)->max(35),
            'until' => $schema->string()->min(10)->max(35),
            'min_count' => $schema->integer()->min(1)->max(10000),
            'sort' => $schema->string()->enum(['occurrences', 'last_seen']),
            'page' => $schema->integer()->min(1)->max(10000),
            'limit' => $schema->integer()->min(1)->max(AggregateQuery::MAX_LIMIT),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'schema_version' => $schema->integer()->required(),
            'groups' => $schema->array()->items($schema->object([]))->required(),
            'summary' => $schema->object([])->required(),
        ];
    }

    public function handle(Beacon $beacon, Principal $caller, Request $request): Response|ResponseFactory
    {
        try {
            return Response::structured($beacon->inspect($caller, ErrorAggregateInspector::NAME, $this->parameters($request))->toArray());
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
            if (! is_string($name) || ! in_array($name, AggregateQuery::PARAMETERS, true)) {
                throw InvalidLogQueryException::filter('Error aggregation rejected an unknown parameter.');
            }

            if ($value !== null && ! is_scalar($value)) {
                throw InvalidLogQueryException::filter('Error aggregation rejected a non-scalar filter.');
            }

            $parameters[$name] = $value;
        }

        return $parameters;
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
