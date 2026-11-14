<?php

declare(strict_types=1);

namespace Beacon\Logs;

/**
 * One parsed log entry. Paths are basenames only.
 */
final readonly class LogRecord
{
    public function __construct(
        public string $timestamp,
        public LogLevel $level,
        public string $channel,
        public string $message,
        public ?string $exceptionClass,
        public ?string $exceptionMessage,
        public ?string $file,
        public ?int $line,
        public bool $tracePresent,
        public bool $traceTruncated,
        /** @var list<string> */
        public array $traceFrames,
    ) {
    }
}
