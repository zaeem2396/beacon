<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Schema\Prose;

/**
 * Slow HTTP requests from recorded Beacon request observations.
 */
final readonly class SlowRequestInspection extends DataObject
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    /**
     * @param list<SlowRequestRecord> $requests
     * @param list<string> $warnings
     */
    public function __construct(
        public array $requests,
        public int $returned,
        public int $slowCount,
        public int $inspected,
        public int $thresholdMs,
        public int $windowMinutes,
        public bool $truncated,
        public string $status,
        public string $summary,
        public array $warnings,
    ) {
        if (! Prose::isSafe($status) || ! Prose::isSafe($summary)) {
            throw new \InvalidArgumentException('Slow request prose must be safe.');
        }

        foreach ($warnings as $warning) {
            if (! is_string($warning) || ! Prose::isSafe($warning)) {
                throw new \InvalidArgumentException('Slow request warnings must be safe prose.');
            }
        }

        $this->schemaVersion = self::SCHEMA_VERSION;
    }
}
