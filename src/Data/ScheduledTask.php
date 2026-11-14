<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * One registered scheduled task definition.
 */
final readonly class ScheduledTask extends DataObject
{
    public function __construct(
        public string $description,
        public ?string $expression,
        public ?string $timezone,
        public bool $withoutOverlapping,
        public bool $runInBackground,
        public bool $evenInMaintenanceMode,
        public ?string $environments,
        public string $executionHistory,
    ) {
    }
}
