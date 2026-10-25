<?php

declare(strict_types=1);

namespace Beacon\Mcp\Tools;

use Beacon\Contracts\RequiresCapability;
use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Data\Observation;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InvalidHealthQueryException;
use Beacon\Health\HealthStatus;
use Beacon\Inspectors\HealthInspector;
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
 * Exposes the {@see HealthInspector} over MCP.
 *
 * The tool takes no arguments. Probes are enabled in beacon.health, not by
 * the caller. A failed check stays inside the report so the others still run.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetHealthTool extends Tool implements RequiresCapability
{
    public const NAME = 'beacon.get_health';

    public const UNAVAILABLE_MESSAGE = 'Health inspection is currently unavailable.';

    protected string $name = self::NAME;

    protected string $title = 'Get health inspection';

    protected string $description = <<<'MARKDOWN'
        Assess this Laravel application with a fixed set of read-only health checks.

        Returns one report: schema_version, status, summary, assessed_at, counts, and checks.
        status is healthy, warning, critical, unknown, or skipped. warning means degraded.
        critical means a confirmed failure. unknown means a check could not decide. skipped
        means the check was disabled or has no safe probe. Counts show each status. Skipped
        and unknown checks are not counted as healthy. The report is healthy only when at
        least one check passed and none are critical, warning, or unknown. One failed check
        does not drop the other results.

        Checks, in order: application, database, cache, queue. application reads the booted
        application and maintenance mode. database runs select 1 on the default connection
        only when BEACON_HEALTH_DATABASE is true, with a timeout of 1 to 5 seconds. cache
        reads one fixed key and discards the value only when BEACON_HEALTH_CACHE is true and
        the driver supports a read without a write. queue classifies the default driver only
        when BEACON_HEALTH_QUEUE is true and never dispatches a job, so it does not report
        the queue as healthy. Configuration and environment findings stay on their own tools.

        Takes no arguments. A caller cannot enable a probe or change a timeout. Does not
        write, migrate, flush cache, enqueue, send mail, or make a network call unless that
        probe is enabled. Does not return credentials, connection strings, or exception text.
        Read-only. Requires the "health" capability.
        MARKDOWN;

    public function capability(): Capability
    {
        return InspectionCapability::Health->capability();
    }

    public function shouldRegister(InspectorRegistry $inspectors): bool
    {
        return $inspectors->enabled(HealthInspector::NAME);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $evidence = $schema->object([
            'name' => $schema->string()->required()
                ->description('Fact the check observed, such as database.probe.'),
            'observation' => $schema->string()->enum($this->values(Observation::cases()))->required()
                ->description('Classification of what was observed. Never a raw configuration value.'),
        ]);

        return [
            'schema_version' => $schema->integer()->required()
                ->description('Version of this response schema.'),
            'status' => $schema->string()->enum($this->values(HealthStatus::cases()))->required()
                ->description('Overall status derived from the checks.'),
            'summary' => $schema->string()->required()
                ->description('Fixed explanation of the overall status.'),
            'assessed_at' => $schema->string()->required()
                ->description('UTC timestamp when the report was built.'),
            'counts' => $schema->object([
                'total' => $schema->integer()->required(),
                'healthy' => $schema->integer()->required(),
                'warning' => $schema->integer()->required(),
                'critical' => $schema->integer()->required(),
                'unknown' => $schema->integer()->required(),
                'skipped' => $schema->integer()->required(),
            ])->required()->description('How many checks landed in each status.'),
            'checks' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required()->description('Stable check identifier.'),
                'description' => $schema->string()->required()->description('What the check is allowed to do.'),
                'status' => $schema->string()->enum($this->values(HealthStatus::cases()))->required(),
                'summary' => $schema->string()->required(),
                'duration_ms' => $schema->integer()->nullable()->required()
                    ->description('Elapsed milliseconds. Null only before the runner records it.'),
                'remediation' => $schema->string()->nullable()->required()
                    ->description('Guidance when the status is not healthy. Null when healthy.'),
                'evidence' => $schema->array()->items($evidence)->required(),
            ]))->required()->description('Check results in a stable order. A failed check does not remove the others.'),
        ];
    }

    public function handle(Beacon $beacon, Principal $caller, Request $request): Response|ResponseFactory
    {
        try {
            if ($request->all() !== []) {
                throw InvalidHealthQueryException::arguments();
            }

            return Response::structured($beacon->inspect($caller, HealthInspector::NAME)->toArray());
        } catch (AuthorizationException) {
            return Response::error(ExecutionGate::DENIED_MESSAGE);
        } catch (InvalidHealthQueryException $exception) {
            return Response::error($exception->getMessage());
        } catch (\Throwable) {
            return Response::error(self::UNAVAILABLE_MESSAGE);
        }
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
