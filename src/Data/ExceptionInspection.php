<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Schema\Prose;

/**
 * Recent exceptions from supported file log sources.
 */
final readonly class ExceptionInspection extends DataObject
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    /**
     * @param list<ExceptionRecord> $exceptions
     * @param list<string> $warnings
     */
    public function __construct(
        public array $exceptions,
        public int $returned,
        public int $inspected,
        public bool $truncated,
        public string $summary,
        public array $warnings,
    ) {
        if (! Prose::isSafe($summary)) {
            throw new \InvalidArgumentException('Exception summary must be safe prose.');
        }

        foreach ($warnings as $warning) {
            if (! is_string($warning) || ! Prose::isSafe($warning)) {
                throw new \InvalidArgumentException('Exception warnings must be safe prose.');
            }
        }

        $this->schemaVersion = self::SCHEMA_VERSION;
    }
}
