<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Requests\SlowRank;

/**
 * One slow HTTP request observation. Excess is duration minus the threshold.
 */
final readonly class SlowRequestRecord extends DataObject
{
    public function __construct(
        public string $requestId,
        public ?string $correlationId,
        public string $method,
        public ?string $routeName,
        public string $routeUri,
        public ?string $action,
        public ?int $status,
        public int $durationMs,
        public int $thresholdMs,
        public int $excessMs,
        public SlowRank $rank,
        public string $startedAt,
        public ?string $environment,
        public bool $hadException,
        public ?string $exceptionId,
        public ?string $exceptionClass,
        public string $correlationKind,
    ) {
    }
}
