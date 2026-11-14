<?php

declare(strict_types=1);

namespace Beacon\Mcp\Tools;

use Beacon\Comparison\ChangeType;
use Beacon\Comparison\CompareQuery;
use Beacon\Comparison\Confidence;
use Beacon\Comparison\DifferenceCategory;
use Beacon\Comparison\Severity;
use Beacon\Comparison\SnapshotLoader;
use Beacon\Contracts\Authorizer;
use Beacon\Contracts\RequiresCapability;
use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InvalidComparisonQueryException;
use Beacon\Exceptions\InvalidSnapshotException;
use Beacon\Inspectors\SchemaComparisonInspector;
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
 * Compares schema snapshots over MCP.
 *
 * Snapshot input is JSON text. The tool does not accept paths, SQL, or PHP.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class CompareSchemaSnapshotsTool extends Tool implements RequiresCapability
{
    public const NAME = 'beacon.compare_schema_snapshots';

    public const UNAVAILABLE_MESSAGE = 'Schema comparison is currently unavailable.';

    protected string $name = self::NAME;

    protected string $title = 'Compare schema snapshots';

    protected string $description = <<<'MARKDOWN'
        Compare two schema snapshots, or one snapshot and the live schema.

        baseline and candidate are snapshot JSON documents from beacon.create_schema_snapshot.
        Set baseline_live or candidate_live instead of that side when the current database
        should be captured. Do not set both live flags. connection selects the live connection.
        category, severity, and object filter the differences. severity is a minimum rank.
        detect_renames is off by default. When it is true, a rename is heuristic and is marked
        as such. page defaults to 1. limit defaults to 100 and cannot exceed 100.

        Differences are structural metadata. They are not proof that a deployment will fail
        or that rows will be lost. Table rows are not read. Snapshot JSON is not executed.
        View definitions are not compared.

        Read-only. Requires the "schema_compare" capability. A live side also requires "schema".
        Allowing only "schema" does not grant this tool.
        MARKDOWN;

    public function capability(): Capability
    {
        return InspectionCapability::SchemaCompare->capability();
    }

    public function shouldRegister(InspectorRegistry $inspectors): bool
    {
        return $inspectors->enabled(SchemaComparisonInspector::NAME);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'baseline' => $schema->string()->max(SnapshotLoader::MAX_BYTES)
                ->description('Baseline snapshot JSON. Omit when baseline_live is true.'),
            'candidate' => $schema->string()->max(SnapshotLoader::MAX_BYTES)
                ->description('Candidate snapshot JSON. Omit when candidate_live is true.'),
            'baseline_live' => $schema->boolean()->description('Capture the baseline from the live schema.'),
            'candidate_live' => $schema->boolean()->description('Capture the candidate from the live schema.'),
            'connection' => $schema->string()->min(1)->max(64)
                ->description('Laravel connection used for a live side. Not a host or DSN.'),
            'category' => $schema->string()->enum($this->values(DifferenceCategory::cases()))
                ->description('table, column, primary_key, index, foreign_key, view, or metadata.'),
            'severity' => $schema->string()->enum($this->values(Severity::cases()))
                ->description('Minimum severity.'),
            'object' => $schema->string()->min(1)->max(64)
                ->description('Exact table, view, or object name. Not a pattern.'),
            'detect_renames' => $schema->boolean()->description('Pair a single added and removed object when the structure matches. Defaults to false.'),
            'page' => $schema->integer()->min(1)->max(CompareQuery::MAX_PAGE),
            'limit' => $schema->integer()->min(1)->max(CompareQuery::MAX_LIMIT),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'schema_version' => $schema->integer()->required(),
            'summary' => $schema->object([
                'differences' => $schema->integer()->required(),
                'critical' => $schema->integer()->required(),
                'high' => $schema->integer()->required(),
                'medium' => $schema->integer()->required(),
                'low' => $schema->integer()->required(),
                'informational' => $schema->integer()->required(),
                'complete' => $schema->boolean()->required(),
                'compared_at' => $schema->string()->required(),
                'summary' => $schema->string()->required(),
            ])->required(),
            'scope' => $schema->object([])->required(),
            'pagination' => $schema->object([
                'page' => $schema->integer()->required(),
                'per_page' => $schema->integer()->required(),
                'total' => $schema->integer()->required(),
                'pages' => $schema->integer()->required(),
                'count' => $schema->integer()->required(),
            ])->required(),
            'differences' => $schema->array()->items($schema->object([
                'id' => $schema->string()->required(),
                'category' => $schema->string()->enum($this->values(DifferenceCategory::cases()))->required(),
                'object_type' => $schema->string()->enum($this->values(DifferenceCategory::cases()))->required(),
                'object_name' => $schema->string()->required(),
                'table' => $schema->string()->nullable()->required(),
                'change' => $schema->string()->enum($this->values(ChangeType::cases()))->required(),
                'field' => $schema->string()->nullable()->required(),
                'before' => $schema->string()->nullable()->required(),
                'after' => $schema->string()->nullable()->required(),
                'severity' => $schema->string()->enum($this->values(Severity::cases()))->required(),
                'confidence' => $schema->string()->enum($this->values(Confidence::cases()))->required(),
                'explanation' => $schema->string()->required(),
                'complete' => $schema->boolean()->required(),
                'heuristic' => $schema->boolean()->required(),
            ]))->required(),
            'limitations' => $schema->array()->items($schema->string())->required(),
            'warnings' => $schema->array()->items($schema->string())->required(),
        ];
    }

    public function handle(Beacon $beacon, Principal $caller, Authorizer $authorizer, Request $request): Response|ResponseFactory
    {
        try {
            $parameters = $this->parameters($request);
            $live = ($parameters['baseline_live'] ?? false) === true || ($parameters['candidate_live'] ?? false) === true;

            if ($live && ! $authorizer->decide($caller, InspectionCapability::Schema->capability())->allowed) {
                return Response::error(ExecutionGate::DENIED_MESSAGE);
            }

            return Response::structured($beacon->inspect($caller, SchemaComparisonInspector::NAME, $parameters)->toArray());
        } catch (AuthorizationException) {
            return Response::error(ExecutionGate::DENIED_MESSAGE);
        } catch (InvalidComparisonQueryException | InvalidSnapshotException $exception) {
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
            if (! is_string($key) || ! in_array($key, CompareQuery::PARAMETERS, true)) {
                throw InvalidComparisonQueryException::unknownParameter();
            }

            if ($value !== null && ! is_scalar($value)) {
                throw InvalidComparisonQueryException::type($key);
            }

            $parameters[$key] = $value;
        }

        return $parameters;
    }

    /**
     * @param array<int, \BackedEnum> $cases
     *
     * @return list<int|string>
     */
    private function values(array $cases): array
    {
        $values = [];

        foreach ($cases as $case) {
            $values[] = $case->value;
        }

        return $values;
    }
}
