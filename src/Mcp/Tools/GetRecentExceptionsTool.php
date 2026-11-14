<?php

declare(strict_types=1);

namespace Beacon\Mcp\Tools;

use Beacon\Contracts\RequiresCapability;
use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InvalidLogQueryException;
use Beacon\Inspectors\ExceptionInspector;
use Beacon\Logs\ExceptionCategory;
use Beacon\Logs\ExceptionQuery;
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
 * Exposes the {@see ExceptionInspector} over MCP.
 *
 * Arguments are literal filters. The tool does not accept paths or raw log bodies,
 * and failures use a fixed message that does not repeat the rejected input.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetRecentExceptionsTool extends Tool implements RequiresCapability
{
    public const NAME = 'beacon.get_recent_exceptions';

    public const UNAVAILABLE_MESSAGE = 'Exception inspection is currently unavailable.';

    protected string $name = self::NAME;

    protected string $title = 'Get recent exceptions';

    protected string $description = <<<'MARKDOWN'
        Read recent exceptions from supported file-based Laravel log channels.

        channel filters to a configured file channel. class is a literal exception
        class name. severity is a minimum log level. category is a fixed exception
        category. since and until are ISO-8601 timestamps. page defaults to 1.
        limit defaults to 25 and cannot exceed 100.

        Messages and frames are sanitized. Secrets and email addresses are redacted.
        Absolute paths are reduced to basenames. Only storage/logs file channels are
        read. Remote or custom log drivers are not opened.

        Read-only. Requires the "exceptions" capability.
        MARKDOWN;

    public function capability(): Capability
    {
        return InspectionCapability::Exceptions->capability();
    }

    public function shouldRegister(InspectorRegistry $inspectors): bool
    {
        return $inspectors->enabled(ExceptionInspector::NAME);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'channel' => $schema->string()->min(1)->max(64)
                ->description('Configured file log channel name.'),
            'class' => $schema->string()->min(1)->max(191)
                ->description('Literal exception class name.'),
            'severity' => $schema->string()->enum($this->values(LogLevel::cases()))
                ->description('Minimum severity.'),
            'category' => $schema->string()->enum($this->values(ExceptionCategory::cases()))
                ->description('Exception category.'),
            'since' => $schema->string()->min(10)->max(35)
                ->description('Include records at or after this timestamp.'),
            'until' => $schema->string()->min(10)->max(35)
                ->description('Include records at or before this timestamp.'),
            'page' => $schema->integer()->min(1)->max(10000),
            'limit' => $schema->integer()->min(1)->max(ExceptionQuery::MAX_LIMIT),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'schema_version' => $schema->integer()->required(),
            'exceptions' => $schema->array()->items($schema->object([]))->required(),
            'returned' => $schema->integer()->required(),
            'inspected' => $schema->integer()->required(),
            'truncated' => $schema->boolean()->required(),
            'summary' => $schema->string()->required(),
            'warnings' => $schema->array()->items($schema->string())->required(),
        ];
    }

    public function handle(Beacon $beacon, Principal $caller, Request $request): Response|ResponseFactory
    {
        try {
            return Response::structured($beacon->inspect($caller, ExceptionInspector::NAME, $this->parameters($request))->toArray());
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
            if (! is_string($name) || ! in_array($name, ExceptionQuery::PARAMETERS, true)) {
                throw InvalidLogQueryException::filter('Exception inspection rejected an unknown parameter.');
            }

            if ($value !== null && ! is_scalar($value)) {
                throw InvalidLogQueryException::filter('Exception inspection rejected a non-scalar filter.');
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
