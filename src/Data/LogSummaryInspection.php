<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Schema\Prose;

/**
 * Structured summary of supported file log channels.
 */
final readonly class LogSummaryInspection extends DataObject
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    /**
     * @param array<string, int> $byLevel
     * @param array<string, int> $byChannel
     * @param list<string> $warnings
     */
    public function __construct(
        public int $inspected,
        public int $errorCount,
        public int $criticalCount,
        public array $byLevel,
        public array $byChannel,
        public ?string $windowStart,
        public ?string $windowEnd,
        public int $parseFailures,
        public bool $truncated,
        public string $summary,
        public array $warnings,
    ) {
        if (! Prose::isSafe($summary)) {
            throw new \InvalidArgumentException('Log summary must be safe prose.');
        }

        foreach ($warnings as $warning) {
            if (! is_string($warning) || ! Prose::isSafe($warning)) {
                throw new \InvalidArgumentException('Log warnings must be safe prose.');
            }
        }

        $this->schemaVersion = self::SCHEMA_VERSION;
    }
}
