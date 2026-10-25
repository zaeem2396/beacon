<?php

declare(strict_types=1);

namespace Beacon\Health\Checks;

use Beacon\Contracts\HealthCheck;
use Beacon\Core\BeaconConfig;
use Beacon\Data\FindingEvidence;
use Beacon\Data\HealthCheckResult;
use Beacon\Data\Observation;
use Beacon\Health\HealthStatus;
use Beacon\Health\SafeName;
use Illuminate\Contracts\Config\Repository;

/**
 * Reports that queue health cannot be confirmed without dispatching a job.
 *
 * A configured driver is not treated as a healthy worker. The check never
 * pushes, pops, or connects to a queue backend.
 */
final class QueueCheck implements HealthCheck
{
    public const NAME = 'queue';

    public const DESCRIPTION = 'Whether queue health can be determined without dispatching a job.';

    /**
     * @var list<string>
     */
    private const DRIVERS = ['background', 'beanstalkd', 'database', 'deferred', 'failover', 'null', 'redis', 'sqs', 'sync'];

    public function __construct(
        private readonly BeaconConfig $config,
        private readonly Repository $repository,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return self::DESCRIPTION;
    }

    public function check(): HealthCheckResult
    {
        if (! $this->config->healthQueueEnabled()) {
            return $this->result(
                HealthStatus::Skipped,
                'The queue check is disabled.',
                'Set BEACON_HEALTH_QUEUE to true to classify the default queue driver.',
                Observation::Disabled,
            );
        }

        $name = $this->repository->get('queue.default');

        if (! is_string($name) || $name === '' || ! SafeName::isIdentifier($name)) {
            return $this->result(
                HealthStatus::Unknown,
                'The default queue connection could not be read safely.',
                'Set the default queue connection to a short name without dots. Beacon does not dispatch a job.',
                Observation::Invalid,
            );
        }

        $definition = $this->repository->get('queue.connections.' . $name);

        if (! is_array($definition)) {
            return $this->result(
                HealthStatus::Unknown,
                'The default queue connection is not registered.',
                'Register the default queue connection. Beacon does not dispatch a job.',
                Observation::Missing,
            );
        }

        $driver = $definition['driver'] ?? null;
        $canonical = is_string($driver) ? strtolower($driver) : null;

        if (! is_string($canonical) || ! in_array($canonical, self::DRIVERS, true)) {
            return $this->result(
                HealthStatus::Unknown,
                'The queue driver could not be read safely. Beacon does not dispatch a job.',
                'Set a recognised queue driver. This check does not enqueue or consume jobs.',
                Observation::Invalid,
            );
        }

        return new HealthCheckResult(
            self::NAME,
            self::DESCRIPTION,
            HealthStatus::Unknown,
            'The queue driver is ' . $canonical . '. Beacon does not dispatch a job, so worker health was not determined.',
            null,
            'Use queue monitoring when it is available. This check does not enqueue or consume jobs.',
            [new FindingEvidence('queue.driver', Observation::Present)],
        );
    }

    private function result(HealthStatus $status, string $summary, string $remediation, Observation $observation): HealthCheckResult
    {
        return new HealthCheckResult(self::NAME, self::DESCRIPTION, $status, $summary, null, $remediation, [
            new FindingEvidence('queue.driver', $observation),
        ]);
    }
}
