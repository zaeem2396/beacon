<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Migrations\MigrationName;
use Beacon\Migrations\MigrationStatus;
use Beacon\Risk\Confidence;
use Beacon\Risk\MigrationPhase;
use Beacon\Risk\RiskCategory;
use Beacon\Risk\RiskText;
use Beacon\Risk\Severity;

/**
 * One evidence-backed risk finding.
 *
 * The snippet is reconstructed operation text. It is not permission to run SQL.
 */
final readonly class RiskFinding extends DataObject
{
    public function __construct(
        public string $ruleId,
        public RiskCategory $category,
        public Severity $severity,
        public Confidence $confidence,
        public string $title,
        public string $explanation,
        public string $migration,
        public string $relativePath,
        public MigrationPhase $phase,
        public ?int $line,
        public ?int $endLine,
        public string $operation,
        public string $snippet,
        public string $reason,
        public string $conditions,
        public string $mitigation,
        public bool $manualReview,
        public MigrationStatus $migrationStatus,
        public bool $tableCreatedHere,
        public ?int $rowEstimate,
    ) {
        if (preg_match('/^MIG[0-9]{3}$/', $ruleId) !== 1) {
            throw new \InvalidArgumentException('Risk rule id must be a MIG identifier.');
        }

        if (! MigrationName::isSafe($migration)) {
            throw new \InvalidArgumentException('Risk finding migration name must be a plain identifier.');
        }

        if (strlen($relativePath) > 220 || ! str_ends_with($relativePath, '.php') || str_contains($relativePath, '..')) {
            throw new \InvalidArgumentException('Risk finding path must be a relative migration file.');
        }

        if (preg_match('#^[A-Za-z0-9][A-Za-z0-9_./-]*$#', $relativePath) !== 1) {
            throw new \InvalidArgumentException('Risk finding path must be a relative migration file.');
        }

        if ($line !== null && $line < 1) {
            throw new \InvalidArgumentException('Risk finding line must be a positive line number.');
        }

        if ($endLine !== null && ($line === null || $endLine < $line)) {
            throw new \InvalidArgumentException('Risk finding line range must end after it starts.');
        }

        if (preg_match('/^[a-z][a-z_]{0,40}$/', $operation) !== 1) {
            throw new \InvalidArgumentException('Risk finding operation must be a plain label.');
        }

        if ($rowEstimate !== null && $rowEstimate < 0) {
            throw new \InvalidArgumentException('Risk row estimate cannot be negative.');
        }

        RiskText::prose($title);
        RiskText::prose($explanation);
        RiskText::prose($reason);
        RiskText::prose($conditions);
        RiskText::prose($mitigation);
        RiskText::snippet($snippet);
    }
}
