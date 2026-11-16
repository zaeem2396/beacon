<?php

declare(strict_types=1);

namespace Beacon\Investigation;

use Beacon\Data\InvestigationCorrelation;
use Beacon\Data\InvestigationEvidenceItem;
use Beacon\Data\InvestigationLead;
use Beacon\Data\InvestigationMissingEvidence;
use Beacon\Data\InvestigationSubject;

/**
 * Deterministic investigation leads. Suggestions only, never root-cause claims.
 */
final class LeadGenerator
{
    public const RULE_REPEATED_FINGERPRINT = 'repeated_exception_fingerprint';

    public const RULE_SLOW_ENDPOINT = 'slow_endpoint_threshold';

    public const RULE_REQUEST_EXCEPTION = 'request_exception_correlation';

    public const RULE_FAILED_JOB_EXCEPTION = 'failed_job_exception_class';

    public const RULE_DATABASE_UNAVAILABLE = 'database_status_unavailable';

    public const RULE_DEPLOYMENT_UNAVAILABLE = 'deployment_history_unavailable';

    public const RULE_CONFIG_CHANGE_UNAVAILABLE = 'configuration_change_history_unavailable';

    /**
     * @param list<InvestigationEvidenceItem> $items
     * @param list<InvestigationCorrelation> $correlations
     * @param list<InvestigationMissingEvidence> $missing
     *
     * @return list<InvestigationLead>
     */
    public function generate(
        InvestigationSubject $subject,
        array $items,
        array $correlations,
        array $missing,
        int $limit,
    ): array {
        $leads = [];

        $fingerprintCounts = [];
        $slowByEndpoint = [];
        $refsByFingerprint = [];
        $refsByEndpoint = [];

        foreach ($items as $item) {
            if ($item->type === 'exception' || $item->type === 'error_pattern') {
                $fingerprint = $item->identifiers['fingerprint'] ?? null;

                if (is_string($fingerprint) && $fingerprint !== '') {
                    $fingerprintCounts[$fingerprint] = ($fingerprintCounts[$fingerprint] ?? 0) + ($item->type === 'error_pattern'
                        ? (int) ($item->identifiers['occurrences'] ?? 1)
                        : 1);
                    $refsByFingerprint[$fingerprint][] = $item->evidenceId;
                }
            }

            if ($item->type === 'slow_request') {
                $endpoint = $item->identifiers['endpoint'] ?? $item->identifiers['route'] ?? null;

                if (is_string($endpoint) && $endpoint !== '') {
                    $slowByEndpoint[$endpoint] = ($slowByEndpoint[$endpoint] ?? 0) + 1;
                    $refsByEndpoint[$endpoint][] = $item->evidenceId;
                }
            }
        }

        foreach ($fingerprintCounts as $fingerprint => $count) {
            if ($count < 2 && $subject->fingerprint !== $fingerprint) {
                continue;
            }

            if ($count < 2) {
                continue;
            }

            $leads[] = new InvestigationLead(
                self::RULE_REPEATED_FINGERPRINT,
                'Repeated exception fingerprint',
                'The same exception fingerprint appeared repeatedly during the investigation window.',
                array_values(array_unique($refsByFingerprint[$fingerprint] ?? [])),
                'direct',
                'Inspect recent exceptions and error aggregates for this fingerprint.',
                'Occurrence counts cover only the inspected evidence window.',
            );
        }

        foreach ($slowByEndpoint as $endpoint => $count) {
            $leads[] = new InvestigationLead(
                self::RULE_SLOW_ENDPOINT,
                'Slow requests on one endpoint',
                'One or more requests to the same endpoint exceeded the configured threshold.',
                array_values(array_unique($refsByEndpoint[$endpoint] ?? [])),
                'direct',
                'Inspect slow requests and request summaries for this endpoint.',
                'Threshold equality is not treated as slow.',
            );
        }

        foreach ($correlations as $correlation) {
            if ($correlation->type === CorrelationType::Direct
                && in_array($correlation->method, ['exact_request_id', 'exact_correlation_id', 'exact_exception_id'], true)) {
                $leads[] = new InvestigationLead(
                    self::RULE_REQUEST_EXCEPTION,
                    'Shared request or exception identifier',
                    'A request and related evidence share an exact identifier.',
                    [$correlation->sourceEvidenceId, $correlation->targetEvidenceId],
                    'direct',
                    'Inspect the linked request and exception evidence together.',
                    'Shared identifiers do not prove a root cause.',
                );

                break;
            }
        }

        foreach ($correlations as $correlation) {
            if ($correlation->method === 'shared_exception_class') {
                $leads[] = new InvestigationLead(
                    self::RULE_FAILED_JOB_EXCEPTION,
                    'Failed job shares an exception class',
                    'A failed job and an exception share a class name without a stronger identifier.',
                    [$correlation->sourceEvidenceId, $correlation->targetEvidenceId],
                    'inferred',
                    'Inspect failed jobs and exceptions for stronger identifiers.',
                    'A shared class name does not prove the job belongs to a request.',
                );

                break;
            }
        }

        foreach ($items as $item) {
            if ($item->source === 'database'
                && (($item->identifiers['status'] ?? null) === 'unavailable'
                    || ($item->identifiers['status'] ?? null) === 'unknown')) {
                $leads[] = new InvestigationLead(
                    self::RULE_DATABASE_UNAVAILABLE,
                    'Database health unavailable',
                    'Database health is currently unavailable or unknown.',
                    [$item->evidenceId],
                    'uncorrelated',
                    'Inspect database status for the default connection.',
                    'Current database status is not historical evidence.',
                );

                break;
            }
        }

        foreach ($missing as $note) {
            if ($note->code === 'deployment_history_unavailable') {
                $leads[] = new InvestigationLead(
                    self::RULE_DEPLOYMENT_UNAVAILABLE,
                    'Deployment history unavailable',
                    'Relevant deployment history is unavailable so deployment correlation cannot be evaluated.',
                    [],
                    'uncorrelated',
                    'Do not infer deployments from git metadata or current state.',
                    'Missing deployment history is not proof that no deploy occurred.',
                );
            }

            if ($note->code === 'configuration_change_history_unavailable') {
                $leads[] = new InvestigationLead(
                    self::RULE_CONFIG_CHANGE_UNAVAILABLE,
                    'Configuration change history unavailable',
                    'Configuration change history is not available so config correlation cannot be evaluated.',
                    [],
                    'uncorrelated',
                    'Do not treat the current configuration as a change record.',
                    'Missing configuration history is not proof that no change occurred.',
                );
            }
        }

        $unique = [];
        $result = [];

        foreach ($leads as $lead) {
            $key = $lead->ruleId . '|' . implode(',', $lead->evidenceRefs);

            if (isset($unique[$key])) {
                continue;
            }

            $unique[$key] = true;
            $result[] = $lead;

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }
}
