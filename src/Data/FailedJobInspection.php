<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Schema\Prose;

/**
 * Failed jobs from a supported failed-job repository.
 */
final readonly class FailedJobInspection extends DataObject
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    /**
     * @param list<FailedJobRecord> $jobs
     * @param list<string> $warnings
     */
    public function __construct(
        public array $jobs,
        public int $returned,
        public ?int $total,
        public bool $truncated,
        public string $driver,
        public string $summary,
        public array $warnings,
    ) {
        if (! Prose::isSafe($summary) || ! Prose::isSafe($driver)) {
            throw new \InvalidArgumentException('Failed job prose must be safe.');
        }

        foreach ($warnings as $warning) {
            if (! is_string($warning) || ! Prose::isSafe($warning)) {
                throw new \InvalidArgumentException('Failed job warnings must be safe prose.');
            }
        }

        $this->schemaVersion = self::SCHEMA_VERSION;
    }
}
