<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Migrations\MigrationName;
use Beacon\Risk\RiskCategory;
use Beacon\Risk\Severity;

/**
 * The filters that selected migrations and findings.
 */
final readonly class RiskScope extends DataObject
{
    public function __construct(
        public bool $filtered,
        public ?string $migration,
        public ?string $path,
        public ?RiskCategory $category,
        public ?Severity $minimumSeverity,
        public bool $pending,
        public bool $includeExecuted,
        public bool $enriched,
    ) {
        if ($migration !== null && ! MigrationName::isSafe($migration)) {
            throw new \InvalidArgumentException('Risk scope migration name must be a plain identifier.');
        }

        if ($path !== null && (strlen($path) > 180 || str_contains($path, '..') || preg_match('#^[A-Za-z0-9][A-Za-z0-9_./-]*$#', $path) !== 1)) {
            throw new \InvalidArgumentException('Risk scope path must be a relative directory.');
        }
    }
}
