<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Schema\Prose;

/**
 * Recent HTTP request observations from Beacon request storage.
 */
final readonly class RequestInspection extends DataObject
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    /**
     * @param list<RequestRecord> $requests
     * @param list<string> $warnings
     */
    public function __construct(
        public array $requests,
        public RequestSummary $summary,
        public string $summaryText,
        public array $warnings,
    ) {
        if (! Prose::isSafe($summaryText)) {
            throw new \InvalidArgumentException('Request summary text must be safe prose.');
        }

        foreach ($warnings as $warning) {
            if (! is_string($warning) || ! Prose::isSafe($warning)) {
                throw new \InvalidArgumentException('Request warnings must be safe prose.');
            }
        }

        $this->schemaVersion = self::SCHEMA_VERSION;
    }
}
