<?php

declare(strict_types=1);

namespace Beacon\Health\Checks;

use Beacon\Contracts\HealthCheck;
use Beacon\Core\BeaconConfig;
use Beacon\Data\FindingEvidence;
use Beacon\Data\HealthCheckResult;
use Beacon\Data\Observation;
use Beacon\Health\CacheProbe;
use Beacon\Health\HealthStatus;
use Beacon\Health\SafeName;
use Illuminate\Contracts\Config\Repository;

/**
 * Optionally reads one fixed key from the default cache store.
 *
 * The value is discarded. Stores that cannot be probed without a write, or
 * whose driver is not a known read-only target, are skipped.
 */
final class CacheCheck implements HealthCheck
{
    public const NAME = 'cache';

    public const DESCRIPTION = 'A read-only probe of the default cache store, when enabled and the driver supports one.';

    /**
     * @var list<string>
     */
    private const READABLE = ['apc', 'array', 'database', 'file', 'memcached', 'redis', 'storage'];

    public function __construct(
        private readonly BeaconConfig $config,
        private readonly Repository $repository,
        private readonly CacheProbe $probe,
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
        if (! $this->config->healthCacheEnabled()) {
            return $this->result(
                HealthStatus::Skipped,
                'The cache probe is disabled.',
                'Set BEACON_HEALTH_CACHE to true to run a read-only cache probe.',
                Observation::Disabled,
            );
        }

        $store = $this->repository->get('cache.default');

        if (! is_string($store) || $store === '' || ! SafeName::isIdentifier($store)) {
            return $this->result(
                HealthStatus::Unknown,
                'The default cache store could not be read safely.',
                'Set the default cache store to a short name without dots.',
                Observation::Invalid,
            );
        }

        $definition = $this->repository->get('cache.stores.' . $store);

        if (! is_array($definition)) {
            return $this->result(
                HealthStatus::Unknown,
                'The default cache store is not registered.',
                'Register the default cache store before enabling the probe.',
                Observation::Missing,
            );
        }

        $driver = $definition['driver'] ?? null;
        $canonical = is_string($driver) ? strtolower($driver) : null;

        if ($canonical === 'null') {
            return $this->result(
                HealthStatus::Skipped,
                'The null cache driver has no dependency to probe.',
                'Use a cache driver that stores values when a connectivity probe is required.',
                Observation::Disabled,
            );
        }

        if (! is_string($canonical) || ! in_array($canonical, self::READABLE, true)) {
            return $this->result(
                HealthStatus::Skipped,
                'This cache driver has no safe read-only probe.',
                'Use a cache driver Beacon can read without writing, or leave the probe disabled.',
                Observation::Disabled,
            );
        }

        try {
            $this->probe->read($store);
        } catch (\Throwable) {
            return $this->result(
                HealthStatus::Critical,
                'The cache store did not answer a read-only probe.',
                'Check that the default cache store accepts reads. Beacon does not include the driver error.',
                Observation::Invalid,
            );
        }

        return $this->result(
            HealthStatus::Healthy,
            'The cache store answered a read-only probe.',
            null,
            Observation::Enabled,
        );
    }

    private function result(HealthStatus $status, string $summary, ?string $remediation, Observation $observation): HealthCheckResult
    {
        return new HealthCheckResult(self::NAME, self::DESCRIPTION, $status, $summary, null, $remediation, [
            new FindingEvidence('cache.probe', $observation),
        ]);
    }
}
