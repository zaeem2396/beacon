<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Schema\Prose;

/**
 * Aggregate metrics for an inspected request window.
 */
final readonly class RequestSummary extends DataObject
{
    /**
     * @param array<array-key, int> $byStatus
     * @param array<string, int> $byMethod
     * @param array<string, int> $byRoute
     */
    public function __construct(
        public int $inspected,
        public int $returned,
        public int $withExceptions,
        public array $byStatus,
        public array $byMethod,
        public array $byRoute,
        public ?float $averageMs,
        public ?float $medianMs,
        public ?float $p95Ms,
        public ?string $windowStart,
        public ?string $windowEnd,
        public bool $truncated,
        public string $collection,
        public string $limitation,
    ) {
        if (! Prose::isSafe($collection) || ! Prose::isSafe($limitation)) {
            throw new \InvalidArgumentException('Request summary prose must be safe.');
        }
    }
}
