<?php

declare(strict_types=1);

namespace Beacon\Risk;

/**
 * Optional schema metadata for risk refinement.
 *
 * applied is false when schema inspection was not allowed or not requested.
 */
final readonly class SchemaLookup
{
    /**
     * @param array<string, TableSnapshot> $tables
     */
    public function __construct(
        public bool $applied,
        public bool $failed,
        public bool $truncated,
        public array $tables,
    ) {
    }

    public static function unused(): self
    {
        return new self(false, false, false, []);
    }
}
