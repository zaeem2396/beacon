<?php

declare(strict_types=1);

namespace Beacon\Investigation;

use Beacon\Data\ErrorAggregate;
use Beacon\Data\ExceptionRecord;
use Beacon\Data\FailedJobRecord;
use Beacon\Data\InvestigationContext;
use Beacon\Data\InvestigationEvidenceItem;
use Beacon\Logs\LogRecord;
use Beacon\Requests\RequestObservation;
use Beacon\Runtime\TextSanitizer;
use Beacon\Schema\Prose;

/**
 * Assembles the final normalized investigation context DTO.
 */
final class ContextBuilder
{
    public function __construct(
        private readonly CorrelationEngine $correlations = new CorrelationEngine(),
        private readonly TimelineBuilder $timeline = new TimelineBuilder(),
        private readonly LeadGenerator $leads = new LeadGenerator(),
    ) {
    }

    public function build(
        InvestigationScope $scope,
        CollectedEvidence $evidence,
        ?string $applicationName,
        ?string $environment,
    ): InvestigationContext {
        $items = [];
        $exceptions = [];
        $patterns = [];
        $requests = [];
        $slow = [];
        $logs = [];
        $jobs = [];
        $runtime = [];
        $database = [];

        foreach ($evidence->exceptions as $index => $record) {
            $item = $this->exceptionItem($record, $index, $scope->limits);
            $exceptions[] = $item;
            $items[] = $item;
        }

        foreach ($evidence->aggregates as $index => $aggregate) {
            $item = $this->aggregateItem($aggregate, $index, $scope->limits);
            $patterns[] = $item;
            $items[] = $item;
        }

        foreach ($evidence->requests as $index => $observation) {
            $item = $this->requestItem($observation, $index, 'request', 'requests');
            $requests[] = $item;
            $items[] = $item;
        }

        foreach ($evidence->slowRequests as $index => $observation) {
            $item = $this->requestItem($observation, $index, 'slow_request', 'slow_requests');
            $slow[] = $item;
            $items[] = $item;
        }

        foreach ($evidence->logs as $index => $record) {
            $item = $this->logItem($record, $index, $scope->limits);
            $logs[] = $item;
            $items[] = $item;
        }

        foreach ($evidence->failedJobs as $index => $job) {
            $item = $this->failedJobItem($job, $index, $scope->limits);
            $jobs[] = $item;
            $items[] = $item;
        }

        if ($evidence->runtimeFacts !== []) {
            $runtimeItem = new InvestigationEvidenceItem(
                evidenceId: 'runtime_0',
                source: 'runtime',
                type: 'runtime',
                timestamp: null,
                summary: 'Current runtime facts were collected for general context.',
                correlation: CorrelationType::Uncorrelated,
                correlationReason: 'General application context without a reliable association.',
                identifiers: $evidence->runtimeFacts,
            );
            $runtime[] = $runtimeItem;
            $items[] = $runtimeItem;
        }

        if ($evidence->databaseFacts !== []) {
            $databaseItem = new InvestigationEvidenceItem(
                evidenceId: 'database_0',
                source: 'database',
                type: 'database',
                timestamp: null,
                summary: 'Current database status was collected for general context.',
                correlation: CorrelationType::Uncorrelated,
                correlationReason: 'General application context without a reliable association.',
                identifiers: $evidence->databaseFacts,
            );
            $database[] = $databaseItem;
            $items[] = $databaseItem;
        }

        $total = count($items);
        $truncated = $evidence->truncated;

        if ($total > $scope->limits->maxTotalEvidence) {
            $items = array_slice($items, 0, $scope->limits->maxTotalEvidence);
            $truncated = true;
            $allowed = [];

            foreach ($items as $item) {
                $allowed[$item->evidenceId] = true;
            }

            $exceptions = array_values(array_filter($exceptions, static fn (InvestigationEvidenceItem $i): bool => isset($allowed[$i->evidenceId])));
            $patterns = array_values(array_filter($patterns, static fn (InvestigationEvidenceItem $i): bool => isset($allowed[$i->evidenceId])));
            $requests = array_values(array_filter($requests, static fn (InvestigationEvidenceItem $i): bool => isset($allowed[$i->evidenceId])));
            $slow = array_values(array_filter($slow, static fn (InvestigationEvidenceItem $i): bool => isset($allowed[$i->evidenceId])));
            $logs = array_values(array_filter($logs, static fn (InvestigationEvidenceItem $i): bool => isset($allowed[$i->evidenceId])));
            $jobs = array_values(array_filter($jobs, static fn (InvestigationEvidenceItem $i): bool => isset($allowed[$i->evidenceId])));
            $runtime = array_values(array_filter($runtime, static fn (InvestigationEvidenceItem $i): bool => isset($allowed[$i->evidenceId])));
            $database = array_values(array_filter($database, static fn (InvestigationEvidenceItem $i): bool => isset($allowed[$i->evidenceId])));
        }

        $correlated = $this->correlations->correlate($evidence->subject, $items);
        $byId = [];

        foreach ($correlated['items'] as $item) {
            $byId[$item->evidenceId] = $item;
        }

        $exceptions = $this->remap($exceptions, $byId);
        $patterns = $this->remap($patterns, $byId);
        $requests = $this->remap($requests, $byId);
        $slow = $this->remap($slow, $byId);
        $logs = $this->remap($logs, $byId);
        $jobs = $this->remap($jobs, $byId);
        $runtime = $this->remap($runtime, $byId);
        $database = $this->remap($database, $byId);

        $timeline = $this->timeline->build($correlated['items'], $scope->limits->maxTimelineEvents);
        $truncated = $truncated || $timeline['truncated'];

        $leads = $this->leads->generate(
            $evidence->subject,
            $correlated['items'],
            $correlated['correlations'],
            $evidence->missing,
            $scope->limits->maxLeads,
        );

        $warnings = $evidence->warnings;

        if ($truncated) {
            $warnings[] = 'Investigation output stopped at configured evidence limits.';
        }

        $failedSources = 0;
        $readySources = 0;

        foreach ($evidence->sourceStatuses as $status) {
            if (in_array($status->status, ['failed', 'unavailable', 'disabled'], true)) {
                $failedSources++;
            }

            if ($status->status === 'ready') {
                $readySources++;
            }
        }

        $completeness = match (true) {
            $failedSources === 0 && ! $truncated => 'complete',
            $readySources > 0 => 'partial',
            default => 'limited',
        };

        $status = match (true) {
            $readySources === 0 && $exceptions === [] && $requests === [] => 'empty',
            $truncated || $failedSources > 0 => 'partial',
            default => 'ready',
        };

        return new InvestigationContext(
            investigationId: substr(hash('sha256', $scope->requestedSince . '|' . $scope->requestedUntil . '|' . $evidence->subject->kind->value . '|' . ($scope->query->requestId ?? $scope->query->correlationId ?? $scope->query->exceptionId ?? $scope->query->fingerprint ?? $scope->query->endpoint ?? 'window')), 0, 16),
            generatedAt: (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.vP'),
            applicationName: $this->safeName($applicationName),
            environment: $this->safeName($environment),
            scope: [
                'request_id' => $scope->query->requestId,
                'correlation_id' => $scope->query->correlationId,
                'exception_id' => $scope->query->exceptionId,
                'fingerprint' => $scope->query->fingerprint,
                'endpoint' => $scope->query->endpoint,
                'severity' => $scope->query->severity?->value,
                'subject_kind' => $scope->subjectKind->value,
                'used_default_window' => $scope->usedDefaultWindow ? 'true' : 'false',
            ],
            requestedWindowStart: $scope->requestedSince,
            requestedWindowEnd: $scope->requestedUntil,
            actualWindowStart: $evidence->actualWindowStart,
            actualWindowEnd: $evidence->actualWindowEnd,
            completeness: $completeness,
            truncated: $truncated,
            sourceStatuses: $evidence->sourceStatuses,
            primarySubject: $evidence->subject,
            exceptions: $exceptions,
            errorPatterns: $patterns,
            requests: $requests,
            slowRequests: $slow,
            logs: $logs,
            failedJobs: $jobs,
            runtime: $runtime,
            database: $database,
            correlations: $correlated['correlations'],
            timeline: $timeline['events'],
            leads: $leads,
            missingEvidence: $evidence->missing,
            status: $status,
            summary: $status === 'empty'
                ? 'No useful investigation evidence was available for the requested scope.'
                : 'Investigation context was built from available Beacon evidence.',
            warnings: array_values(array_unique(array_filter(
                $warnings,
                static fn (string $warning): bool => Prose::isSafe($warning),
            ))),
        );
    }

    /**
     * @param list<InvestigationEvidenceItem> $items
     * @param array<string, InvestigationEvidenceItem> $byId
     *
     * @return list<InvestigationEvidenceItem>
     */
    private function remap(array $items, array $byId): array
    {
        $result = [];

        foreach ($items as $item) {
            $result[] = $byId[$item->evidenceId] ?? $item;
        }

        return $result;
    }

    private function exceptionItem(ExceptionRecord $record, int $index, InvestigationLimits $limits): InvestigationEvidenceItem
    {
        $frames = array_slice($record->traceFrames, 0, $limits->maxStackFrames);

        return new InvestigationEvidenceItem(
            evidenceId: 'exc_' . $record->exceptionId . '_' . $index,
            source: 'exceptions',
            type: 'exception',
            timestamp: $record->recordedAt,
            summary: $this->safeSummary('Exception ' . $this->shortClass($record->className) . ' was observed.'),
            correlation: CorrelationType::Uncorrelated,
            correlationReason: 'Pending correlation.',
            identifiers: [
                'exception_id' => $record->exceptionId,
                'fingerprint' => $record->fingerprint,
                'exception_class' => $record->className,
                'severity' => $record->severity->value,
                'category' => $record->category->value,
                'channel' => $record->channel,
                'message' => TextSanitizer::sanitize($record->message, $limits->maxMessageLength),
                'trace_frames' => implode(' | ', $frames),
            ],
        );
    }

    private function aggregateItem(ErrorAggregate $aggregate, int $index, InvestigationLimits $limits): InvestigationEvidenceItem
    {
        return new InvestigationEvidenceItem(
            evidenceId: 'agg_' . $aggregate->fingerprint . '_' . $index,
            source: 'error_aggregate',
            type: 'error_pattern',
            timestamp: $aggregate->lastSeenAt,
            summary: $this->safeSummary('Exception pattern ' . $this->shortClass($aggregate->className) . ' occurred ' . $aggregate->occurrences . ' times.'),
            correlation: CorrelationType::Uncorrelated,
            correlationReason: 'Pending correlation.',
            identifiers: [
                'fingerprint' => $aggregate->fingerprint,
                'exception_class' => $aggregate->className,
                'occurrences' => $aggregate->occurrences,
                'first_seen_at' => $aggregate->firstSeenAt,
                'last_seen_at' => $aggregate->lastSeenAt,
                'severity' => $aggregate->severity->value,
                'category' => $aggregate->category->value,
                'message' => TextSanitizer::sanitize($aggregate->message, $limits->maxMessageLength),
            ],
        );
    }

    private function requestItem(
        RequestObservation $observation,
        int $index,
        string $type,
        string $source,
    ): InvestigationEvidenceItem {
        $endpoint = $observation->routeName ?? $observation->routeUri;

        return new InvestigationEvidenceItem(
            evidenceId: ($type === 'slow_request' ? 'slow_' : 'req_') . $observation->requestId . '_' . $index,
            source: $source,
            type: $type,
            timestamp: $observation->startedAt,
            summary: $this->safeSummary(
                $type === 'slow_request'
                    ? 'Slow HTTP request was recorded for ' . $this->safeToken($endpoint) . '.'
                    : 'HTTP request was recorded for ' . $this->safeToken($endpoint) . '.',
            ),
            correlation: CorrelationType::Uncorrelated,
            correlationReason: 'Pending correlation.',
            identifiers: [
                'request_id' => $observation->requestId,
                'correlation_id' => $observation->correlationId,
                'method' => $observation->method,
                'endpoint' => $endpoint,
                'route' => $observation->routeUri,
                'status' => $observation->status,
                'duration_ms' => $observation->durationMs,
                'exception_id' => $observation->exceptionId,
                'exception_class' => $observation->exceptionClass,
                'had_exception' => $observation->hadException ? 'true' : 'false',
            ],
        );
    }

    private function logItem(LogRecord $record, int $index, InvestigationLimits $limits): InvestigationEvidenceItem
    {
        return new InvestigationEvidenceItem(
            evidenceId: 'log_' . $index . '_' . substr(hash('sha256', $record->timestamp . '|' . $record->channel . '|' . $record->message), 0, 8),
            source: 'logs',
            type: 'log',
            timestamp: $record->timestamp,
            summary: $this->safeSummary('Log ' . $record->level->value . ' observation was collected.'),
            correlation: CorrelationType::Uncorrelated,
            correlationReason: 'Pending correlation.',
            identifiers: [
                'level' => $record->level->value,
                'channel' => $record->channel,
                'exception_class' => $record->exceptionClass,
                'message' => TextSanitizer::sanitize($record->message, $limits->maxMessageLength),
            ],
        );
    }

    private function failedJobItem(FailedJobRecord $job, int $index, InvestigationLimits $limits): InvestigationEvidenceItem
    {
        return new InvestigationEvidenceItem(
            evidenceId: 'job_' . $job->failedJobId . '_' . $index,
            source: 'failed_jobs',
            type: 'failed_job',
            timestamp: $job->failedAt,
            summary: $this->safeSummary('Failed job was recorded without payload contents.'),
            correlation: CorrelationType::Uncorrelated,
            correlationReason: 'Pending correlation.',
            identifiers: [
                'failed_job_id' => $job->failedJobId,
                'connection' => $job->connection,
                'queue' => $job->queue,
                'exception_class' => $job->exceptionClass,
                'message' => TextSanitizer::sanitize($job->exceptionSummary, $limits->maxMessageLength),
            ],
        );
    }

    private function shortClass(string $class): string
    {
        $parts = explode('\\', $class);

        return $this->safeToken($parts[array_key_last($parts)] ?? $class);
    }

    private function safeToken(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '', $value) ?? '';

        return $clean === '' ? 'unknown' : substr($clean, 0, 64);
    }

    private function safeSummary(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9 .,_-]/', '', $value) ?? 'Evidence item.';
        $clean = trim($clean);

        if ($clean === '' || ! Prose::isSafe($clean)) {
            return 'Evidence item was collected.';
        }

        return substr($clean, 0, 240);
    }

    private function safeName(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $clean = preg_replace('/[^A-Za-z0-9 .,_-]/', '', $value) ?? '';
        $clean = trim($clean);

        return $clean === '' ? null : substr($clean, 0, 64);
    }
}
