<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * One failed job without payload or serialized job data.
 */
final readonly class FailedJobRecord extends DataObject
{
    public function __construct(
        public string $failedJobId,
        public ?string $connection,
        public ?string $queue,
        public ?string $exceptionClass,
        public string $exceptionSummary,
        public ?string $failedAt,
    ) {
    }
}
