<?php

declare(strict_types=1);

namespace Beacon\Investigation;

use Beacon\Data\InvestigationCorrelation;
use Beacon\Data\InvestigationEvidenceItem;
use Beacon\Data\InvestigationSubject;

/**
 * Deterministic evidence correlation. Exact identifiers beat time proximity.
 * Temporal proximity alone is never direct and never implies causation.
 */
final class CorrelationEngine
{
    /**
     * @param list<InvestigationEvidenceItem> $items
     *
     * @return array{items: list<InvestigationEvidenceItem>, correlations: list<InvestigationCorrelation>}
     */
    public function correlate(InvestigationSubject $subject, array $items): array
    {
        $classified = [];
        $relationships = [];
        $seen = [];

        foreach ($items as $item) {
            $type = $this->classify($subject, $item);
            $classified[] = new InvestigationEvidenceItem(
                evidenceId: $item->evidenceId,
                source: $item->source,
                type: $item->type,
                timestamp: $item->timestamp,
                summary: $item->summary,
                correlation: $type,
                correlationReason: $this->reason($type, $subject, $item),
                identifiers: $item->identifiers,
            );
        }

        foreach ($classified as $left) {
            foreach ($classified as $right) {
                if ($left->evidenceId === $right->evidenceId) {
                    continue;
                }

                $pair = $this->pairKey($left->evidenceId, $right->evidenceId);

                if (isset($seen[$pair])) {
                    continue;
                }

                $link = $this->link($left, $right);

                if ($link === null) {
                    continue;
                }

                $seen[$pair] = true;
                $relationships[] = $link;
            }
        }

        return ['items' => $classified, 'correlations' => $relationships];
    }

    private function classify(InvestigationSubject $subject, InvestigationEvidenceItem $item): CorrelationType
    {
        if ($subject->requestId !== null
            && (($item->identifiers['request_id'] ?? null) === $subject->requestId
                || ($item->identifiers['correlation_id'] ?? null) === $subject->requestId)) {
            return CorrelationType::Direct;
        }

        if ($subject->correlationId !== null
            && (($item->identifiers['correlation_id'] ?? null) === $subject->correlationId
                || ($item->identifiers['request_id'] ?? null) === $subject->correlationId)) {
            return CorrelationType::Direct;
        }

        if ($subject->exceptionId !== null
            && ($item->identifiers['exception_id'] ?? null) === $subject->exceptionId) {
            return CorrelationType::Direct;
        }

        if ($subject->fingerprint !== null
            && ($item->identifiers['fingerprint'] ?? null) === $subject->fingerprint) {
            return CorrelationType::Direct;
        }

        if ($subject->endpoint !== null) {
            $endpoint = $item->identifiers['endpoint'] ?? $item->identifiers['route'] ?? null;

            if (is_string($endpoint) && $endpoint === $subject->endpoint) {
                return CorrelationType::Inferred;
            }
        }

        if (in_array($item->source, ['runtime', 'database', 'queue', 'migration_risk'], true)) {
            return CorrelationType::Uncorrelated;
        }

        if ($subject->kind === SubjectKind::TimeWindow) {
            return CorrelationType::Inferred;
        }

        if ($item->timestamp !== null) {
            return CorrelationType::Inferred;
        }

        return CorrelationType::Uncorrelated;
    }

    private function reason(
        CorrelationType $type,
        InvestigationSubject $subject,
        InvestigationEvidenceItem $item,
    ): string {
        return match ($type) {
            CorrelationType::Direct => match (true) {
                $subject->requestId !== null && (($item->identifiers['request_id'] ?? null) === $subject->requestId) => 'Exact request id match.',
                $subject->correlationId !== null || $subject->requestId !== null => 'Exact correlation id match.',
                $subject->exceptionId !== null => 'Exact exception id match.',
                $subject->fingerprint !== null => 'Exact exception fingerprint match.',
                default => 'Exact identifier match.',
            },
            CorrelationType::Inferred => $subject->endpoint !== null
                ? 'Same endpoint within the investigation window.'
                : 'Temporal proximity within the investigation window.',
            CorrelationType::Uncorrelated => 'General application context without a reliable association.',
        };
    }

    private function link(
        InvestigationEvidenceItem $left,
        InvestigationEvidenceItem $right,
    ): ?InvestigationCorrelation {
        $leftRequest = $left->identifiers['request_id'] ?? null;
        $rightRequest = $right->identifiers['request_id'] ?? null;
        $leftCorrelation = $left->identifiers['correlation_id'] ?? null;
        $rightCorrelation = $right->identifiers['correlation_id'] ?? null;
        $leftException = $left->identifiers['exception_id'] ?? null;
        $rightException = $right->identifiers['exception_id'] ?? null;
        $leftFingerprint = $left->identifiers['fingerprint'] ?? null;
        $rightFingerprint = $right->identifiers['fingerprint'] ?? null;
        $leftClass = $left->identifiers['exception_class'] ?? null;
        $rightClass = $right->identifiers['exception_class'] ?? null;

        if (is_string($leftRequest) && $leftRequest !== '' && $leftRequest === $rightRequest) {
            return new InvestigationCorrelation(
                $left->evidenceId,
                $right->evidenceId,
                CorrelationType::Direct,
                'exact_request_id',
                'Both items share the same request id.',
            );
        }

        if (is_string($leftCorrelation) && $leftCorrelation !== '' && $leftCorrelation === $rightCorrelation) {
            return new InvestigationCorrelation(
                $left->evidenceId,
                $right->evidenceId,
                CorrelationType::Direct,
                'exact_correlation_id',
                'Both items share the same correlation id.',
            );
        }

        if (is_string($leftException) && $leftException !== '' && $leftException === $rightException) {
            return new InvestigationCorrelation(
                $left->evidenceId,
                $right->evidenceId,
                CorrelationType::Direct,
                'exact_exception_id',
                'Both items share the same exception id.',
            );
        }

        if (is_string($leftFingerprint) && $leftFingerprint !== '' && $leftFingerprint === $rightFingerprint) {
            return new InvestigationCorrelation(
                $left->evidenceId,
                $right->evidenceId,
                CorrelationType::Direct,
                'exact_fingerprint',
                'Both items share the same exception fingerprint.',
            );
        }

        if ($left->type === 'failed_job'
            && $right->type === 'exception'
            && is_string($leftClass)
            && $leftClass !== ''
            && $leftClass === $rightClass) {
            return new InvestigationCorrelation(
                $left->evidenceId,
                $right->evidenceId,
                CorrelationType::Inferred,
                'shared_exception_class',
                'Failed job and exception share a class name only.',
            );
        }

        if ($right->type === 'failed_job'
            && $left->type === 'exception'
            && is_string($rightClass)
            && $rightClass !== ''
            && $rightClass === $leftClass) {
            return new InvestigationCorrelation(
                $left->evidenceId,
                $right->evidenceId,
                CorrelationType::Inferred,
                'shared_exception_class',
                'Failed job and exception share a class name only.',
            );
        }

        $leftEndpoint = $left->identifiers['endpoint'] ?? $left->identifiers['route'] ?? null;
        $rightEndpoint = $right->identifiers['endpoint'] ?? $right->identifiers['route'] ?? null;

        if (is_string($leftEndpoint) && $leftEndpoint !== '' && $leftEndpoint === $rightEndpoint) {
            return new InvestigationCorrelation(
                $left->evidenceId,
                $right->evidenceId,
                CorrelationType::Inferred,
                'same_endpoint_window',
                'Items share an endpoint inside the investigation window.',
            );
        }

        return null;
    }

    private function pairKey(string $left, string $right): string
    {
        return $left < $right ? $left . '|' . $right : $right . '|' . $left;
    }
}
