<?php

declare(strict_types=1);

namespace Beacon\Mcp\Tools;

use Beacon\Contracts\Authorizer;
use Beacon\Contracts\RequiresCapability;
use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Database\ConnectionStatus;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InvalidRiskQueryException;
use Beacon\Inspectors\MigrationRiskInspector;
use Beacon\Mcp\ExecutionGate;
use Beacon\Migrations\MigrationStatus;
use Beacon\Risk\Confidence;
use Beacon\Risk\MigrationPhase;
use Beacon\Risk\RiskCategory;
use Beacon\Risk\RiskQuery;
use Beacon\Risk\Severity;
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
 * Exposes static migration risk analysis over MCP.
 *
 * The caller cannot pass PHP, SQL, or an absolute path. enrich is not a
 * caller argument. Schema metadata is included only when the schema
 * capability is allowed.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class AnalyzeMigrationRisksTool extends Tool implements RequiresCapability
{
    public const NAME = 'beacon.analyze_migration_risks';

    public const UNAVAILABLE_MESSAGE = 'Migration risk analysis is currently unavailable.';

    protected string $name = self::NAME;

    protected string $title = 'Analyze migration risks';

    protected string $description = <<<'MARKDOWN'
        Analyze Laravel migration source for destructive, locking, compatibility, and data-risk patterns.

        Use this before a deploy when an investigation needs evidence-backed findings about pending
        migrations. The default set is pending migrations. include_executed also analyzes migrations
        already recorded in the repository. migration is one exact migration name. path is a relative
        registered directory such as database/migrations. category limits findings to one risk category.
        severity is a minimum rank: critical, high, medium, low, or informational. page defaults to 1.
        limit defaults to 100 and must be an integer from 1 to 100.

        Findings are heuristic static analysis. They are not a guarantee that a migration will succeed,
        fail, lock a table, or lose data. A conservative warning means the analyzer could not prove the
        operation was safe. Migration PHP is not included or executed. SQL is not run. Schema metadata
        is applied only when the schema capability is also allowed, and it never includes table rows.

        Read-only. Requires the "migration_risk" capability. The "migrations", "database", and "schema"
        capabilities do not grant this tool. Allowing "schema" as well can refine findings with table
        metadata. The timeout is BEACON_MIGRATION_RISK_TIMEOUT, from 1 to 5 seconds, default 2.
        MARKDOWN;

    public function capability(): Capability
    {
        return InspectionCapability::MigrationRisk->capability();
    }

    public function shouldRegister(InspectorRegistry $inspectors): bool
    {
        return $inspectors->enabled(MigrationRiskInspector::NAME);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'connection' => $schema->string()->min(1)->max(64)
                ->description('Laravel connection name. Defaults to the application default. Not a host or DSN.'),
            'migration' => $schema->string()->min(1)->max(200)
                ->description('Exact migration name. Not a path and not a regular expression.'),
            'path' => $schema->string()->min(1)->max(180)
                ->description('Relative registered migration directory. Not an absolute path.'),
            'category' => $schema->string()->enum($this->values(RiskCategory::cases()))
                ->description('data_loss, locking, compatibility, existing_data, performance, foreign_key, or transaction.'),
            'severity' => $schema->string()->enum($this->values(Severity::cases()))
                ->description('Minimum severity. critical, high, medium, low, or informational.'),
            'pending' => $schema->boolean()
                ->description('Include pending migrations. Defaults to true.'),
            'include_executed' => $schema->boolean()
                ->description('Also analyze migrations already recorded in the repository.'),
            'page' => $schema->integer()->min(1)->max(RiskQuery::MAX_PAGE)
                ->description('Page number. Defaults to 1.'),
            'limit' => $schema->integer()->min(1)->max(RiskQuery::MAX_LIMIT)
                ->description('Page size. Defaults to 100.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'schema_version' => $schema->integer()->required(),
            'connection' => $schema->object([
                'name' => $schema->string()->nullable()->required(),
                'driver' => $schema->string()->nullable()->required(),
                'status' => $schema->string()->enum($this->values(ConnectionStatus::cases()))->required(),
            ])->required(),
            'repository' => $schema->object([
                'available' => $schema->boolean()->required(),
                'empty' => $schema->boolean()->required(),
                'table' => $schema->string()->nullable()->required(),
            ])->required(),
            'summary' => $schema->object([
                'analyzed' => $schema->integer()->required()->description('Migration files whose source was analyzed.'),
                'findings' => $schema->integer()->required(),
                'critical' => $schema->integer()->required(),
                'high' => $schema->integer()->required(),
                'medium' => $schema->integer()->required(),
                'low' => $schema->integer()->required(),
                'informational' => $schema->integer()->required(),
                'manual_review' => $schema->integer()->required(),
                'inspected_at' => $schema->string()->required(),
                'summary' => $schema->string()->required(),
            ])->required(),
            'scope' => $schema->object([
                'filtered' => $schema->boolean()->required(),
                'migration' => $schema->string()->nullable()->required(),
                'path' => $schema->string()->nullable()->required(),
                'category' => $schema->string()->enum($this->values(RiskCategory::cases()))->nullable()->required(),
                'minimum_severity' => $schema->string()->enum($this->values(Severity::cases()))->nullable()->required(),
                'pending' => $schema->boolean()->required(),
                'include_executed' => $schema->boolean()->required(),
                'enriched' => $schema->boolean()->required()->description('True when schema metadata was applied.'),
            ])->required(),
            'pagination' => $schema->object([
                'page' => $schema->integer()->required(),
                'per_page' => $schema->integer()->required(),
                'total' => $schema->integer()->required(),
                'pages' => $schema->integer()->required(),
                'count' => $schema->integer()->required(),
            ])->required(),
            'findings' => $schema->array()->items($schema->object([
                'rule_id' => $schema->string()->required(),
                'category' => $schema->string()->enum($this->values(RiskCategory::cases()))->required(),
                'severity' => $schema->string()->enum($this->values(Severity::cases()))->required(),
                'confidence' => $schema->string()->enum($this->values(Confidence::cases()))->required(),
                'title' => $schema->string()->required(),
                'explanation' => $schema->string()->required(),
                'migration' => $schema->string()->required(),
                'relative_path' => $schema->string()->required(),
                'phase' => $schema->string()->enum($this->values(MigrationPhase::cases()))->required(),
                'line' => $schema->integer()->nullable()->required(),
                'end_line' => $schema->integer()->nullable()->required(),
                'operation' => $schema->string()->required(),
                'snippet' => $schema->string()->required(),
                'reason' => $schema->string()->required(),
                'conditions' => $schema->string()->required(),
                'mitigation' => $schema->string()->required(),
                'manual_review' => $schema->boolean()->required(),
                'migration_status' => $schema->string()->enum($this->values(MigrationStatus::cases()))->required(),
                'table_created_here' => $schema->boolean()->required(),
                'row_estimate' => $schema->integer()->nullable()->required()->description('information_schema estimate, not a row count.'),
            ]))->required(),
            'limitations' => $schema->array()->items($schema->object([
                'migration' => $schema->string()->nullable()->required(),
                'message' => $schema->string()->required(),
            ]))->required()->description('Files that could not be analyzed are not treated as safe.'),
            'warnings' => $schema->array()->items($schema->string())->required(),
        ];
    }

    public function handle(Beacon $beacon, Principal $caller, Authorizer $authorizer, Request $request): Response|ResponseFactory
    {
        try {
            $enrich = $authorizer->decide($caller, InspectionCapability::Schema->capability())->allowed;

            return Response::structured($beacon->inspect($caller, MigrationRiskInspector::NAME, $this->parameters($request, $enrich))->toArray());
        } catch (AuthorizationException) {
            return Response::error(ExecutionGate::DENIED_MESSAGE);
        } catch (InvalidRiskQueryException $exception) {
            return Response::error($exception->getMessage());
        } catch (\Throwable) {
            return Response::error(self::UNAVAILABLE_MESSAGE);
        }
    }

    /**
     * @return array<string, scalar|null>
     */
    private function parameters(Request $request, bool $enrich): array
    {
        $parameters = [];

        foreach ($request->all() as $key => $value) {
            if ($key === 'enrich') {
                continue;
            }

            if (! is_string($key) || ! in_array($key, RiskQuery::PARAMETERS, true)) {
                throw InvalidRiskQueryException::unknownParameter();
            }

            if ($value !== null && ! is_scalar($value)) {
                throw InvalidRiskQueryException::type($key);
            }

            $parameters[$key] = $value;
        }

        $parameters['enrich'] = $enrich;

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
