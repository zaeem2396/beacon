<?php

declare(strict_types=1);

namespace Beacon\Requests;

/**
 * One request whose recorded duration exceeded its threshold.
 */
final readonly class SlowRequest
{
    public function __construct(
        public RequestObservation $observation,
        public int $thresholdMs,
        public int $excessMs,
        public SlowRank $rank,
    ) {
    }
}
