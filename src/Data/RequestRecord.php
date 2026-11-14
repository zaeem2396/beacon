<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * One sanitized HTTP request observation for MCP and CLI output.
 */
final readonly class RequestRecord extends DataObject
{
    public function __construct(
        public string $requestId,
        public ?string $correlationId,
        public string $method,
        public ?string $routeName,
        public string $routeUri,
        public ?string $action,
        public ?int $status,
        public string $startedAt,
        public ?string $finishedAt,
        public ?int $durationMs,
        public ?string $environment,
        public string $requestType,
        public bool $hadException,
        public ?string $exceptionId,
        public ?string $exceptionClass,
    ) {
    }
}
