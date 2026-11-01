<?php

declare(strict_types=1);

namespace Beacon\Risk;

use Beacon\Data\RiskFinding;

/**
 * Builds a finding from one recognized operation.
 */
final class FindingFactory
{
    public function make(
        MigrationFacts $facts,
        Operation $operation,
        string $ruleId,
        RiskCategory $category,
        Severity $severity,
        string $title,
        string $explanation,
        string $reason,
        string $conditions,
        string $mitigation,
        ?Confidence $confidence = null,
        ?bool $manualReview = null,
        ?int $line = null,
    ): RiskFinding {
        $confidence ??= $operation->conditional ? Confidence::Medium : Confidence::High;
        $manualReview ??= $confidence === Confidence::Low || $severity->meets(Severity::Medium);
        $snapshot = $facts->table($operation->table);

        return new RiskFinding(
            $ruleId,
            $category,
            $severity,
            $confidence,
            $title,
            $explanation,
            $facts->name,
            $facts->relativePath,
            $operation->phase,
            $line ?? $operation->line,
            $operation->endLine,
            $operation->kind->value,
            $operation->snippet,
            $reason,
            $conditions,
            $mitigation,
            $manualReview,
            $facts->status,
            $operation->onNewTable || $facts->createdInUp($operation->table),
            $snapshot !== null && $snapshot->exists ? $snapshot->rowEstimate : null,
        );
    }
}
