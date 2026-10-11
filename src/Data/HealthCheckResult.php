<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Health\HealthStatus;

final readonly class HealthCheckResult extends DataObject
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $name,
        public HealthStatus $status,
        public string $summary,
        public array $details = [],
    ) {
    }
}
