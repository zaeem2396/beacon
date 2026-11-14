<?php

declare(strict_types=1);

namespace Beacon\Requests;

/**
 * One sanitized HTTP request observation ready for storage or DTO mapping.
 */
final readonly class RequestObservation
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
        public bool $hadException,
        public ?string $exceptionId,
        public ?string $exceptionClass,
    ) {
    }
}
