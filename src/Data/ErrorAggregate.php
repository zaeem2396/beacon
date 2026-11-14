<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Logs\ExceptionCategory;
use Beacon\Logs\LogLevel;

/**
 * One grouped exception fingerprint within the inspected window.
 */
final readonly class ErrorAggregate extends DataObject
{
    /**
     * @param list<string> $channels
     * @param list<string> $files
     */
    public function __construct(
        public string $fingerprint,
        public string $className,
        public string $message,
        public ExceptionCategory $category,
        public LogLevel $severity,
        public int $occurrences,
        public string $firstSeenAt,
        public string $lastSeenAt,
        public array $channels,
        public array $files,
        public bool $tracePresent,
        public bool $traceTruncated,
    ) {
    }
}
