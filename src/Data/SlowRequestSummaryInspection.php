<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Schema\Prose;

/**
 * Endpoint slow-request summaries for the inspected storage window.
 */
final readonly class SlowRequestSummaryInspection extends DataObject
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    /**
     * @param list<SlowEndpointRecord> $endpoints
     * @param list<string> $warnings
     */
    public function __construct(
        public array $endpoints,
        public int $returned,
        public int $inspected,
        public int $slowCount,
        public int $thresholdMs,
        public int $windowMinutes,
        public int $minimumSamples,
        public bool $truncated,
        public string $status,
        public string $summary,
        public array $warnings,
    ) {
        if (! Prose::isSafe($status) || ! Prose::isSafe($summary)) {
            throw new \InvalidArgumentException('Slow request summary prose must be safe.');
        }

        foreach ($warnings as $warning) {
            if (! is_string($warning) || ! Prose::isSafe($warning)) {
                throw new \InvalidArgumentException('Slow request summary warnings must be safe prose.');
            }
        }

        $this->schemaVersion = self::SCHEMA_VERSION;
    }
}
