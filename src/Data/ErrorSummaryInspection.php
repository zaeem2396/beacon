<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * Error summary metrics for the inspected log window.
 */
final readonly class ErrorSummaryInspection extends DataObject
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    public function __construct(
        public ErrorSummary $summary,
    ) {
        $this->schemaVersion = self::SCHEMA_VERSION;
    }
}
