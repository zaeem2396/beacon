<?php

declare(strict_types=1);

namespace Beacon\Health;

use Beacon\Contracts\HealthCheck;
use Beacon\Health\Checks\ApplicationCheck;
use Beacon\Health\Checks\CacheCheck;
use Beacon\Health\Checks\DatabaseCheck;
use Beacon\Health\Checks\QueueCheck;

/**
 * The health checks shipped in this release, in report order.
 *
 * Adding a check means adding it here. The report is a list, so the MCP
 * response shape does not change.
 */
final class HealthChecks
{
    public function __construct(
        private readonly ApplicationCheck $application,
        private readonly DatabaseCheck $database,
        private readonly CacheCheck $cache,
        private readonly QueueCheck $queue,
    ) {
    }

    /**
     * @return list<HealthCheck>
     */
    public function all(): array
    {
        return [$this->application, $this->database, $this->cache, $this->queue];
    }
}
