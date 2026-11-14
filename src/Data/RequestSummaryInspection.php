<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * Request summary metrics without the individual request rows.
 */
final readonly class RequestSummaryInspection extends DataObject
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    public function __construct(
        public RequestSummary $summary,
    ) {
        $this->schemaVersion = self::SCHEMA_VERSION;
    }
}
