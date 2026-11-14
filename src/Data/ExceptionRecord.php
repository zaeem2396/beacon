<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Logs\ExceptionCategory;
use Beacon\Logs\LogLevel;

/**
 * One sanitized exception occurrence from a supported log source.
 */
final readonly class ExceptionRecord extends DataObject
{
    /**
     * @param list<string> $traceFrames
     */
    public function __construct(
        public string $exceptionId,
        public string $fingerprint,
        public string $className,
        public string $message,
        public ExceptionCategory $category,
        public LogLevel $severity,
        public string $recordedAt,
        public string $channel,
        public ?string $file,
        public ?int $line,
        public ?string $environment,
        public bool $tracePresent,
        public bool $traceTruncated,
        public array $traceFrames,
    ) {
    }
}
