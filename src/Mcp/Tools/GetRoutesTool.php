<?php

declare(strict_types=1);

namespace Beacon\Mcp\Tools;

use Beacon\Contracts\RequiresCapability;
use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Data\FindingStatus;
use Beacon\Data\Observation;
use Beacon\Data\RouteRecord;
use Beacon\Data\Severity;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InvalidRouteQueryException;
use Beacon\Inspectors\RouteInspector;
use Beacon\Inspectors\Routes\MatchMode;
use Beacon\Inspectors\Routes\RouteQuery;
use Beacon\Inspectors\Routes\RouteText;
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
 * Exposes the {@see RouteInspector} over MCP.
 *
 * Arguments are literal filters. The tool does not dispatch routes, and
 * failures use a fixed message that does not repeat the rejected input.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetRoutesTool extends Tool implements RequiresCapability
{
    public const NAME = 'beacon.get_routes';

    public const UNAVAILABLE_MESSAGE = 'Route inspection is currently unavailable.';

    protected string $name = self::NAME;

    protected string $title = 'Get route inspection';

    protected string $description = <<<'MARKDOWN'
        Inspect registered routes and deterministic findings for this Laravel application.

        Returns one page of route records and findings for the whole route table. A finding can
        mention a route that is not on the current page. Each record has methods, uri, name,
        domain, middleware, action and framework_generated. Null means the value is missing or
        was withheld, not that it was guessed. framework_generated is null because route metadata
        does not reliably say whether the framework registered the route.

        Filters are optional and literal. method is one of GET, HEAD, POST, PUT, PATCH, DELETE or
        OPTIONS. uri matches a URI pattern, and uri_match is contains (default) or exact. A single
        leading slash on uri is ignored, so "/users" and "users" match the same route. name and
        name_match work the same way for route names. middleware must equal one normalized
        middleware string. page defaults to 1 and must be an integer from 1 to 10000. limit
        defaults to 50 and must be an integer from 1 to 100. Values above the maximum are rejected.
        There is no regular-expression, SQL or expression mode.

        Checks, in order: duplicate_route_names, sensitive_route_paths,
        authentication_middleware_excluded. duplicate_route_names reports a repeated disclosed
        name. sensitive_route_paths is a heuristic whole-segment match and does not mean the route is vulnerable, reachable or unauthenticated. authentication_middleware_excluded reports
        only when a recognised authentication middleware name is explicitly excluded. An unfamiliar
        middleware alias is not treated as missing authentication. pass, fail, risk and unknown
        are the statuses. risk is a potential issue, not a confirmed vulnerability. unknown means
        the check could not be evaluated. pass and unknown use severity none.

        Does not call route handlers, dispatch requests, construct controllers, or resolve
        controller middleware. Does not return closures, middleware objects, request objects,
        filesystem paths or source code. Read-only. Requires the "routes" capability.
        MARKDOWN;

    public function capability(): Capability
    {
        return InspectionCapability::Routes->capability();
    }

    public function shouldRegister(InspectorRegistry $inspectors): bool
    {
        return $inspectors->enabled(RouteInspector::NAME);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'method' => $schema->string()->enum(RouteRecord::METHODS)
                ->description('HTTP verb to keep. Compared case-insensitively against the route methods.'),
            'uri' => $schema->string()->min(1)->max(RouteText::MAX_URI)
                ->description('Literal URI or substring. A single leading slash is ignored. Not a regular expression.'),
            'uri_match' => $schema->string()->enum($this->values(MatchMode::cases()))
                ->description('contains (default) or exact. Applied to the URI filter.'),
            'name' => $schema->string()->min(1)->max(RouteText::MAX_NAME)
                ->description('Literal route name or substring. Not a regular expression.'),
            'name_match' => $schema->string()->enum($this->values(MatchMode::cases()))
                ->description('contains (default) or exact. Applied to the name filter.'),
            'middleware' => $schema->string()->min(1)->max(RouteText::MAX_MIDDLEWARE)
                ->description('Exact normalized middleware string, such as auth or throttle:60,1.'),
            'page' => $schema->integer()->min(1)->max(RouteQuery::MAX_PAGE)
                ->description('Page number. Defaults to 1.'),
            'limit' => $schema->integer()->min(1)->max(RouteQuery::MAX_LIMIT)
                ->description('Page size. Defaults to 50. Larger values are rejected.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $evidence = $schema->object([
            'name' => $schema->string()->required()
                ->description('Fact the check observed, such as route.name or route.uri.'),
            'observation' => $schema->string()->enum($this->values(Observation::cases()))->required()
                ->description('Classification of what was observed. Never a raw route object.'),
        ]);

        return [
            'schema_version' => $schema->integer()->required()
                ->description('Version of this response schema. Fields may be added within a version but are never removed or redefined.'),
            'pagination' => $schema->object([
                'page' => $schema->integer()->required()->description('Requested page, starting at 1.'),
                'per_page' => $schema->integer()->required()->description('Requested page size after validation.'),
                'total' => $schema->integer()->required()->description('Routes that matched the filter, before this page was sliced.'),
                'pages' => $schema->integer()->required()->description('Number of pages. Zero when nothing matched.'),
                'count' => $schema->integer()->required()->description('Routes returned in this page.'),
            ])->required()->description('Pagination for the route records. Findings are not paginated.'),
            'routes' => $schema->array()->items($schema->object([
                'methods' => $schema->array()->items($schema->string()->enum(RouteRecord::METHODS))->required()
                    ->description('HTTP methods in canonical order. GET routes also include HEAD when Laravel registered both.'),
                'uri' => $schema->string()->nullable()->required()
                    ->description('URI pattern. Null when the pattern is not safe to disclose.'),
                'name' => $schema->string()->nullable()->required()
                    ->description('Route name. Null when the route is unnamed or the name is not safe to disclose.'),
                'domain' => $schema->string()->nullable()->required()
                    ->description('Route domain. Null when no domain is configured or it is not safe to disclose.'),
                'middleware' => $schema->array()->items($schema->string())->required()
                    ->description('Normalized middleware names. Closures are the string "closure". Unsafe parameters are dropped.'),
                'action' => $schema->string()->nullable()->required()
                    ->description('closure, or a Class@method identifier. Null when the action is not safe to disclose.'),
                'framework_generated' => $schema->boolean()->nullable()->required()
                    ->description('Null. Route metadata does not reliably identify framework-generated routes.'),
            ]))->required()->description('The requested page of route records, in a stable order.'),
            'findings' => $schema->array()->items($schema->object([
                'id' => $schema->string()->required()->description('Stable check identifier.'),
                'status' => $schema->string()->enum($this->values(FindingStatus::cases()))->required()
                    ->description('pass, fail, risk, or unknown.'),
                'severity' => $schema->string()->enum($this->values(Severity::cases()))->required()
                    ->description('none, low, medium, or high. Pass and unknown are always none.'),
                'title' => $schema->string()->required(),
                'description' => $schema->string()->required(),
                'evidence' => $schema->array()->items($evidence)->required()
                    ->description('Classified observations that support the status.'),
                'remediation' => $schema->string()->nullable()->required()
                    ->description('Fixed guidance when the status is not pass. Null on pass.'),
            ]))->required()->description('Deterministic checks over the whole route table, in a stable order.'),
        ];
    }

    public function handle(Beacon $beacon, Principal $caller, Request $request): Response|ResponseFactory
    {
        try {
            return Response::structured($beacon->inspect($caller, RouteInspector::NAME, $this->parameters($request))->toArray());
        } catch (AuthorizationException) {
            return Response::error(ExecutionGate::DENIED_MESSAGE);
        } catch (InvalidRouteQueryException $exception) {
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

        foreach ($request->all() as $key => $value) {
            if (! is_string($key) || ! in_array($key, RouteQuery::PARAMETERS, true)) {
                throw InvalidRouteQueryException::unknownParameter();
            }

            if ($value !== null && ! is_scalar($value)) {
                throw InvalidRouteQueryException::type($key);
            }

            $parameters[$key] = $value;
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
