<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Schema\Prose;

/**
 * Registered scheduler definitions without executing tasks.
 */
final readonly class SchedulerInspection extends DataObject
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    /**
     * @param list<ScheduledTask> $tasks
     * @param list<string> $warnings
     */
    public function __construct(
        public array $tasks,
        public int $returned,
        public string $summary,
        public array $warnings,
    ) {
        if (! Prose::isSafe($summary)) {
            throw new \InvalidArgumentException('Scheduler summary must be safe prose.');
        }

        foreach ($warnings as $warning) {
            if (! is_string($warning) || ! Prose::isSafe($warning)) {
                throw new \InvalidArgumentException('Scheduler warnings must be safe prose.');
            }
        }

        $this->schemaVersion = self::SCHEMA_VERSION;
    }
}
