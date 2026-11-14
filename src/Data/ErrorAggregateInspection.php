<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * Grouped exceptions for the inspected log window.
 */
final readonly class ErrorAggregateInspection extends DataObject
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    /**
     * @param list<ErrorAggregate> $groups
     */
    public function __construct(
        public array $groups,
        public ErrorSummary $summary,
    ) {
        $this->schemaVersion = self::SCHEMA_VERSION;
    }
}
