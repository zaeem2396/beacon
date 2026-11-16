<?php

declare(strict_types=1);

namespace Beacon\Requests;

/**
 * Duration statistics for one method and normalized endpoint.
 */
final readonly class SlowEndpointSummary
{
    /**
     * @param array<array-key, int> $byStatus
     */
    public function __construct(
        public string $method,
        public string $endpoint,
        public int $thresholdMs,
        public int $samples,
        public int $slowCount,
        public float $slowPercent,
        public ?float $averageMs,
        public ?float $medianMs,
        public ?float $p95Ms,
        public ?float $p99Ms,
        public ?int $maximumMs,
        public array $byStatus,
        public int $exceptionCount,
        public bool $sufficientSamples,
    ) {
    }
}
