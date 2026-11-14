<?php

declare(strict_types=1);

namespace Beacon\Inspectors;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Data\QueueConnectionInfo;
use Beacon\Data\QueueInspection;
use Beacon\Exceptions\InvalidRuntimeQueryException;
use Beacon\Health\SafeName;
use Beacon\QueueIntelligence\FailedJobReader;
use Beacon\QueueIntelligence\QueueStatus;
use Beacon\Schema\Prose;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * Queue configuration and safely observable backend state.
 *
 * @implements Inspector<QueueInspection>
 */
final class QueueInspector implements Inspector
{
    public const NAME = 'queue';

    /**
     * @var list<string>
     */
    private const DRIVERS = ['background', 'beanstalkd', 'database', 'deferred', 'failover', 'null', 'redis', 'sqs', 'sync'];

    public function __construct(
        private readonly Repository $config,
        private readonly FailedJobReader $failedJobs,
        private readonly ?RedisFactory $redis = null,
    ) {
    }

    public function capability(): Capability
    {
        return InspectionCapability::Queue->capability();
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function inspect(array $parameters = []): Data
    {
        if ($parameters !== []) {
            throw new InvalidRuntimeQueryException('Queue inspection does not accept arguments.');
        }

        $default = $this->name($this->config->get('queue.default'));
        $connections = [];
        $configured = $this->config->get('queue.connections');

        if (is_array($configured)) {
            foreach ($configured as $name => $definition) {
                if (! is_string($name) || ! SafeName::isIdentifier($name) || ! is_array($definition)) {
                    continue;
                }

                $connections[] = $this->connection($name, $definition);
            }
        }

        usort($connections, static fn (QueueConnectionInfo $a, QueueConnectionInfo $b): int => $a->name <=> $b->name);

        $failed = $this->failedJobs->read(1);
        $warnings = [];

        if ($failed['warning'] !== null && Prose::isSafe($failed['warning'])) {
            $warnings[] = $failed['warning'];
        }

        return new QueueInspection(
            defaultConnection: $default,
            connections: $connections,
            failedJobCount: $failed['total'],
            workerEvidence: 'Worker processes were not inspected.',
            summary: 'Queue configuration was read. A configured driver is not proof that workers are running.',
            warnings: $warnings,
        );
    }

    /**
     * @param array<array-key, mixed> $definition
     */
    private function connection(string $name, array $definition): QueueConnectionInfo
    {
        $driver = $definition['driver'] ?? null;
        $canonical = is_string($driver) ? strtolower($driver) : null;
        $safeDriver = is_string($canonical) && in_array($canonical, self::DRIVERS, true) ? $canonical : null;
        $queueName = $this->name($definition['queue'] ?? null);
        $retry = $definition['retry_after'] ?? null;
        $retryAfter = is_int($retry) && $retry > 0 && $retry < 86400 ? $retry : null;

        if ($safeDriver === 'sync' || $safeDriver === 'null' || $safeDriver === 'deferred') {
            return new QueueConnectionInfo(
                $name,
                $safeDriver,
                $queueName,
                $retryAfter,
                QueueStatus::Ready,
                0,
                'Local driver. No remote backend was contacted.',
            );
        }

        if ($safeDriver === 'redis') {
            return $this->redis($name, $safeDriver, $queueName, $retryAfter, $definition);
        }

        if ($safeDriver === 'database') {
            return new QueueConnectionInfo(
                $name,
                $safeDriver,
                $queueName,
                $retryAfter,
                QueueStatus::Unknown,
                null,
                'Database queue backlog was not counted in this release.',
            );
        }

        return new QueueConnectionInfo(
            $name,
            $safeDriver,
            $queueName,
            $retryAfter,
            $safeDriver === null ? QueueStatus::Unknown : QueueStatus::Unsupported,
            null,
            $safeDriver === null
                ? 'The queue driver could not be read safely.'
                : 'Backend metrics are not available for this driver.',
        );
    }

    /**
     * @param array<array-key, mixed> $definition
     */
    private function redis(
        string $name,
        string $driver,
        ?string $queueName,
        ?int $retryAfter,
        array $definition,
    ): QueueConnectionInfo {
        if ($this->redis === null) {
            return new QueueConnectionInfo(
                $name,
                $driver,
                $queueName,
                $retryAfter,
                QueueStatus::Unknown,
                null,
                'Redis was not available in this process.',
            );
        }

        $connection = $definition['connection'] ?? 'default';
        $queue = is_string($definition['queue'] ?? null) ? (string) $definition['queue'] : 'default';

        if (! is_string($connection) || ! SafeName::isIdentifier($connection) || ! SafeName::isIdentifier($queue)) {
            return new QueueConnectionInfo(
                $name,
                $driver,
                $queueName,
                $retryAfter,
                QueueStatus::Unknown,
                null,
                'The Redis queue identifiers were not safe to read.',
            );
        }

        try {
            $client = $this->redis->connection($connection);
            $pending = $client->llen('queues:' . $queue);

            return new QueueConnectionInfo(
                $name,
                $driver,
                $queueName ?? $queue,
                $retryAfter,
                QueueStatus::Ready,
                is_int($pending) ? $pending : (is_numeric($pending) ? (int) $pending : null),
                'Pending count is the Redis list length for this queue.',
            );
        } catch (\Throwable) {
            return new QueueConnectionInfo(
                $name,
                $driver,
                $queueName,
                $retryAfter,
                QueueStatus::Unavailable,
                null,
                'The Redis queue backend could not be reached.',
            );
        }
    }

    private function name(mixed $value): ?string
    {
        return is_string($value) && SafeName::isIdentifier($value) ? $value : null;
    }
}
