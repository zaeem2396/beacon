<?php

declare(strict_types=1);

namespace Beacon\Risk;

/**
 * Column metadata used only to refine a finding.
 *
 * Values stored in the column are not included.
 */
final readonly class ColumnSnapshot
{
    public function __construct(
        public bool $nullable,
        public ?int $characterLength,
        public ?int $numericPrecision,
        public ?int $numericScale,
        public string $typeName,
    ) {
    }
}
