<?php

declare(strict_types=1);

namespace Beacon\Investigation;

use Beacon\Core\BeaconConfig;
use Beacon\Core\InspectorRegistry;
use Beacon\Data\DatabaseStatus;
use Beacon\Data\ErrorAggregate;
use Beacon\Data\ExceptionRecord;
use Beacon\Data\FailedJobRecord;
use Beacon\Data\InvestigationMissingEvidence;
use Beacon\Data\InvestigationSourceStatus;
use Beacon\Data\InvestigationSubject;
use Beacon\Data\RuntimeInspection;
use Beacon\Inspectors\DatabaseInspector;
use Beacon\Inspectors\MigrationRiskInspector;
use Beacon\Inspectors\QueueInspector;
use Beacon\Inspectors\RuntimeInspector;
use Beacon\Logs\AggregateQuery;
use Beacon\Logs\ExceptionAggregator;
use Beacon\Logs\ExceptionCollector;
use Beacon\Logs\ExceptionQuery;
use Beacon\Logs\LogReader;
use Beacon\Logs\LogRecord;
use Beacon\QueueIntelligence\FailedJobReader;
use Beacon\Requests\RequestObservation;
use Beacon\Requests\RequestQuery;
use Beacon\Requests\RequestStore;
use Beacon\Requests\SlowRequestDetector;

/**
 * Gathers evidence from existing Beacon modules without duplicating storage.
 */
final class EvidenceCollector
{
    public function __construct(
        private readonly BeaconConfig $config,
        private readonly InspectorRegistry $inspectors,
        private readonly RequestStore $requests,
        private readonly ExceptionCollector $exceptions,
        private readonly ExceptionAggregator $aggregator,
        private readonly LogReader $logs,
        private readonly FailedJobReader $failedJobs,
        private readonly RuntimeInspector $runtime,
        private readonly DatabaseInspector $database,
        private readonly QueueInspector $queue,
        private readonly MigrationRiskInspector $migrationRisk,
        private readonly SlowRequestDetector $slowDetector = new SlowRequestDetector(),
    ) {
    }

    public function collect(InvestigationScope $scope): CollectedEvidence
    {
        $missing = [];
        $statuses = [];
        $warnings = [];
        $truncated = false;

        $requests = $this->collectRequests($scope, $statuses, $missing, $truncated);
        $slow = $this->collectSlowRequests($scope, $requests, $statuses, $missing, $truncated);
        $exceptionRecords = $this->collectExceptions($scope, $statuses, $missing, $truncated);
        $aggregates = $this->collectAggregates($scope, $exceptionRecords, $statuses, $truncated);
        $logRecords = $this->collectLogs($scope, $statuses, $missing, $truncated);
        $jobs = $this->collectFailedJobs($scope, $statuses, $missing, $truncated);
        $runtimeFacts = $this->collectRuntime($statuses, $missing);
        $databaseFacts = $this->collectDatabase($statuses, $missing);
        $this->collectQueueStatus($statuses, $missing);
        $this->collectMigrationRisk($statuses, $missing, $warnings);
        $this->recordUnavailableHistory($missing);

        $subject = $this->resolveSubject($scope, $requests, $exceptionRecords, $aggregates);
        [$windowStart, $windowEnd] = $this->actualWindow(
            $requests,
            $exceptionRecords,
            $logRecords,
            $jobs,
            $slow,
        );

        if ($scope->usedDefaultWindow) {
            $warnings[] = 'A default investigation time window was applied.';
        }

        return new CollectedEvidence(
            subject: $subject,
            exceptions: $exceptionRecords,
            aggregates: $aggregates,
            requests: $requests,
            slowRequests: $slow,
            logs: $logRecords,
            failedJobs: $jobs,
            sourceStatuses: $statuses,
            missing: $missing,
            runtimeFacts: $runtimeFacts,
            databaseFacts: $databaseFacts,
            warnings: $warnings,
            actualWindowStart: $windowStart,
            actualWindowEnd: $windowEnd,
            truncated: $truncated,
        );
    }

    /**
     * @param list<InvestigationSourceStatus> $statuses
     * @param list<InvestigationMissingEvidence> $missing
     *
     * @return list<RequestObservation>
     */
    private function collectRequests(
        InvestigationScope $scope,
        array &$statuses,
        array &$missing,
        bool &$truncated,
    ): array {
        if (! $this->inspectors->enabled('requests')) {
            $statuses[] = new InvestigationSourceStatus('requests', 'disabled', false, 0, 'Request inspection is disabled.');
            $missing[] = new InvestigationMissingEvidence('requests_disabled', 'requests', 'Request inspection is disabled.');

            return [];
        }

        if (! $this->config->requestCollectionEnabled()) {
            $statuses[] = new InvestigationSourceStatus('requests', 'disabled', false, 0, 'Request collection is disabled.');
            $missing[] = new InvestigationMissingEvidence('request_collection_disabled', 'requests', 'Request collection is disabled.');

            return [];
        }

        if (! $this->requests->available()) {
            $statuses[] = new InvestigationSourceStatus('requests', 'unavailable', false, 0, 'Request storage is unavailable.');
            $missing[] = new InvestigationMissingEvidence('request_storage_unavailable', 'requests', 'Request storage is unavailable.');

            return [];
        }

        try {
            $correlation = $scope->query->correlationId ?? $scope->query->requestId;
            $query = new RequestQuery(
                $scope->requestedSince,
                $scope->requestedUntil,
                null,
                $scope->query->endpoint,
                null,
                null,
                null,
                $correlation,
                $scope->limits->maxRequests,
                1,
            );
            $search = $this->requests->search($query);
            $records = $search['records'];

            if ($scope->query->requestId !== null) {
                $records = array_values(array_filter(
                    $records,
                    static fn (RequestObservation $r): bool => $r->requestId === $scope->query->requestId
                        || $r->correlationId === $scope->query->requestId,
                ));
            }

            if ($search['truncated'] || count($search['records']) > $scope->limits->maxRequests) {
                $truncated = true;
            }

            $page = array_slice($records, 0, $scope->limits->maxRequests);
            $statuses[] = new InvestigationSourceStatus(
                'requests',
                count($page) === 0 ? 'empty' : 'ready',
                $search['truncated'] || count($records) > count($page),
                count($page),
                count($page) === 0 ? 'No matching request observations were found.' : 'Request observations were collected.',
            );

            return $page;
        } catch (\Throwable) {
            $statuses[] = new InvestigationSourceStatus('requests', 'failed', false, 0, 'Request collection failed.');
            $missing[] = new InvestigationMissingEvidence('request_collection_failed', 'requests', 'Request collection failed.');

            return [];
        }
    }

    /**
     * @param list<RequestObservation> $requests
     * @param list<InvestigationSourceStatus> $statuses
     * @param list<InvestigationMissingEvidence> $missing
     *
     * @return list<RequestObservation>
     */
    private function collectSlowRequests(
        InvestigationScope $scope,
        array $requests,
        array &$statuses,
        array &$missing,
        bool &$truncated,
    ): array {
        if (! $this->inspectors->enabled('slow_requests')) {
            $statuses[] = new InvestigationSourceStatus('slow_requests', 'disabled', false, 0, 'Slow request inspection is disabled.');
            $missing[] = new InvestigationMissingEvidence('slow_requests_disabled', 'slow_requests', 'Slow request inspection is disabled.');

            return [];
        }

        if (! $this->config->requestCollectionEnabled() || ! $this->requests->available()) {
            $statuses[] = new InvestigationSourceStatus('slow_requests', 'unavailable', false, 0, 'Slow request storage is unavailable.');
            $missing[] = new InvestigationMissingEvidence('slow_requests_unavailable', 'slow_requests', 'Slow request storage is unavailable.');

            return [];
        }

        try {
            $thresholds = $this->config->slowRequestThresholds();
            $pool = $requests;

            if ($pool === []) {
                $analysis = RequestQuery::analysis(
                    $scope->requestedSince,
                    $scope->requestedUntil,
                    null,
                    $scope->query->endpoint,
                    null,
                    $thresholds->floor(),
                    RequestQuery::MAX_ANALYSIS,
                );
                $search = $this->requests->search($analysis);
                $pool = $search['records'];

                if ($search['truncated']) {
                    $truncated = true;
                }
            }

            $slow = $this->slowDetector->detect($pool, $thresholds);
            $observations = [];

            foreach (array_slice($slow, 0, $scope->limits->maxSlowRequests) as $item) {
                $observations[] = $item->observation;
            }

            if (count($slow) > count($observations)) {
                $truncated = true;
            }

            $statuses[] = new InvestigationSourceStatus(
                'slow_requests',
                count($observations) === 0 ? 'empty' : 'ready',
                count($slow) > count($observations),
                count($observations),
                count($observations) === 0 ? 'No slow requests matched the investigation window.' : 'Slow requests were classified from recorded observations.',
            );

            return $observations;
        } catch (\Throwable) {
            $statuses[] = new InvestigationSourceStatus('slow_requests', 'failed', false, 0, 'Slow request collection failed.');
            $missing[] = new InvestigationMissingEvidence('slow_requests_failed', 'slow_requests', 'Slow request collection failed.');

            return [];
        }
    }

    /**
     * @param list<InvestigationSourceStatus> $statuses
     * @param list<InvestigationMissingEvidence> $missing
     *
     * @return list<ExceptionRecord>
     */
    private function collectExceptions(
        InvestigationScope $scope,
        array &$statuses,
        array &$missing,
        bool &$truncated,
    ): array {
        if (! $this->inspectors->enabled('exceptions')) {
            $statuses[] = new InvestigationSourceStatus('exceptions', 'disabled', false, 0, 'Exception inspection is disabled.');
            $missing[] = new InvestigationMissingEvidence('exceptions_disabled', 'exceptions', 'Exception inspection is disabled.');

            return [];
        }

        try {
            $params = [
                'since' => $scope->requestedSince,
                'until' => $scope->requestedUntil,
                'limit' => $scope->limits->maxExceptions,
                'page' => 1,
            ];

            if ($scope->query->severity !== null) {
                $params['severity'] = $scope->query->severity->value;
            }

            $query = ExceptionQuery::from($params);
            $collected = $this->exceptions->collect($query);
            $records = $collected['records'];

            if ($scope->query->exceptionId !== null) {
                $records = array_values(array_filter(
                    $records,
                    static fn (ExceptionRecord $r): bool => $r->exceptionId === $scope->query->exceptionId,
                ));
            }

            if ($scope->query->fingerprint !== null) {
                $records = array_values(array_filter(
                    $records,
                    static fn (ExceptionRecord $r): bool => $r->fingerprint === $scope->query->fingerprint,
                ));
            }

            if ($collected['truncated'] || count($collected['records']) > $scope->limits->maxExceptions) {
                $truncated = true;
            }

            $page = array_slice($records, 0, $scope->limits->maxExceptions);
            $statuses[] = new InvestigationSourceStatus(
                'exceptions',
                count($page) === 0 ? 'empty' : 'ready',
                $collected['truncated'] || count($records) > count($page),
                count($page),
                count($page) === 0 ? 'No matching exceptions were found in the inspected window.' : 'Exceptions were read from supported file log channels.',
            );

            return $page;
        } catch (\Throwable) {
            $statuses[] = new InvestigationSourceStatus('exceptions', 'failed', false, 0, 'Exception sources were unavailable.');
            $missing[] = new InvestigationMissingEvidence('exceptions_unavailable', 'exceptions', 'Exception sources were unavailable.');

            return [];
        }
    }

    /**
     * @param list<ExceptionRecord> $records
     * @param list<InvestigationSourceStatus> $statuses
     *
     * @return list<ErrorAggregate>
     */
    private function collectAggregates(
        InvestigationScope $scope,
        array $records,
        array &$statuses,
        bool &$truncated,
    ): array {
        if (! $this->inspectors->enabled('error_aggregate')) {
            $statuses[] = new InvestigationSourceStatus('error_aggregate', 'disabled', false, 0, 'Error aggregation is disabled.');

            return [];
        }

        try {
            $params = [
                'since' => $scope->requestedSince,
                'until' => $scope->requestedUntil,
                'limit' => $scope->limits->maxExceptions,
                'page' => 1,
                'min_count' => 1,
                'sort' => 'occurrences',
            ];

            if ($scope->query->severity !== null) {
                $params['severity'] = $scope->query->severity->value;
            }

            $query = AggregateQuery::from($params);
            $result = $this->aggregator->aggregate($records, $query, false, count($records));
            $groups = $result['groups'];

            if ($scope->query->fingerprint !== null) {
                $groups = array_values(array_filter(
                    $groups,
                    static fn (ErrorAggregate $g): bool => $g->fingerprint === $scope->query->fingerprint,
                ));
            }

            $page = array_slice($groups, 0, $scope->limits->maxExceptions);

            if (count($groups) > count($page)) {
                $truncated = true;
            }

            $statuses[] = new InvestigationSourceStatus(
                'error_aggregate',
                count($page) === 0 ? 'empty' : 'ready',
                count($groups) > count($page),
                count($page),
                count($page) === 0 ? 'No error aggregates matched the investigation window.' : 'Exceptions were grouped by fingerprint.',
            );

            return $page;
        } catch (\Throwable) {
            $statuses[] = new InvestigationSourceStatus('error_aggregate', 'failed', false, 0, 'Error aggregation failed.');

            return [];
        }
    }

    /**
     * @param list<InvestigationSourceStatus> $statuses
     * @param list<InvestigationMissingEvidence> $missing
     *
     * @return list<LogRecord>
     */
    private function collectLogs(
        InvestigationScope $scope,
        array &$statuses,
        array &$missing,
        bool &$truncated,
    ): array {
        if (! $this->inspectors->enabled('logs')) {
            $statuses[] = new InvestigationSourceStatus('logs', 'disabled', false, 0, 'Log inspection is disabled.');
            $missing[] = new InvestigationMissingEvidence('logs_disabled', 'logs', 'Log inspection is disabled.');

            return [];
        }

        try {
            $read = $this->logs->read(null, $scope->limits->maxLogs);
            $records = [];

            foreach ($read['records'] as $record) {
                if (! $this->withinWindow($record->timestamp, $scope->requestedSince, $scope->requestedUntil)) {
                    continue;
                }

                if ($scope->query->severity !== null && $record->level->rank() < $scope->query->severity->rank()) {
                    continue;
                }

                $records[] = $record;
            }

            if ($read['truncated'] || count($records) > $scope->limits->maxLogs) {
                $truncated = true;
            }

            $page = array_slice($records, 0, $scope->limits->maxLogs);
            $statuses[] = new InvestigationSourceStatus(
                'logs',
                count($page) === 0 ? 'empty' : 'ready',
                $read['truncated'] || count($records) > count($page),
                count($page),
                count($page) === 0 ? 'No log observations matched the investigation window.' : 'Bounded log observations were collected.',
            );

            return $page;
        } catch (\Throwable) {
            $statuses[] = new InvestigationSourceStatus('logs', 'failed', false, 0, 'Log sources were unavailable.');
            $missing[] = new InvestigationMissingEvidence('logs_unavailable', 'logs', 'Log sources were unavailable.');

            return [];
        }
    }

    /**
     * @param list<InvestigationSourceStatus> $statuses
     * @param list<InvestigationMissingEvidence> $missing
     *
     * @return list<FailedJobRecord>
     */
    private function collectFailedJobs(
        InvestigationScope $scope,
        array &$statuses,
        array &$missing,
        bool &$truncated,
    ): array {
        if (! $this->inspectors->enabled('failed_jobs')) {
            $statuses[] = new InvestigationSourceStatus('failed_jobs', 'disabled', false, 0, 'Failed job inspection is disabled.');
            $missing[] = new InvestigationMissingEvidence('failed_jobs_disabled', 'failed_jobs', 'Failed job inspection is disabled.');

            return [];
        }

        try {
            $read = $this->failedJobs->read($scope->limits->maxFailedJobs);
            $jobs = [];

            foreach ($read['jobs'] as $job) {
                if ($job->failedAt !== null && ! $this->withinWindow($job->failedAt, $scope->requestedSince, $scope->requestedUntil)) {
                    continue;
                }

                $jobs[] = $job;
            }

            if ($read['truncated'] || count($jobs) > $scope->limits->maxFailedJobs) {
                $truncated = true;
            }

            if ($read['warning'] !== null) {
                $missing[] = new InvestigationMissingEvidence('failed_jobs_limited', 'failed_jobs', 'Failed job source reported a limitation.');
            }

            $page = array_slice($jobs, 0, $scope->limits->maxFailedJobs);
            $statuses[] = new InvestigationSourceStatus(
                'failed_jobs',
                count($page) === 0 ? 'empty' : 'ready',
                $read['truncated'] || count($jobs) > count($page),
                count($page),
                count($page) === 0 ? 'No failed jobs matched the investigation window.' : 'Failed jobs were read without payloads.',
            );

            return $page;
        } catch (\Throwable) {
            $statuses[] = new InvestigationSourceStatus('failed_jobs', 'failed', false, 0, 'Failed job sources were unavailable.');
            $missing[] = new InvestigationMissingEvidence('failed_jobs_unavailable', 'failed_jobs', 'Failed job sources were unavailable.');

            return [];
        }
    }

    /**
     * @param list<InvestigationSourceStatus> $statuses
     * @param list<InvestigationMissingEvidence> $missing
     *
     * @return array<string, scalar|null>
     */
    private function collectRuntime(array &$statuses, array &$missing): array
    {
        if (! $this->inspectors->enabled(RuntimeInspector::NAME)) {
            $statuses[] = new InvestigationSourceStatus('runtime', 'disabled', false, 0, 'Runtime inspection is disabled.');
            $missing[] = new InvestigationMissingEvidence('runtime_disabled', 'runtime', 'Runtime inspection is disabled.');

            return [];
        }

        try {
            /** @var RuntimeInspection $inspection */
            $inspection = $this->runtime->inspect([]);
            $statuses[] = new InvestigationSourceStatus('runtime', 'ready', false, 1, 'Runtime facts were collected.');

            return [
                'php_version' => $inspection->php->version,
                'laravel_version' => $inspection->laravel->version,
                'environment' => $inspection->laravel->environment,
                'debug' => $inspection->laravel->debug ? 'true' : 'false',
                'queue_driver' => $inspection->drivers->queue,
                'cache_driver' => $inspection->drivers->cache,
            ];
        } catch (\Throwable) {
            $statuses[] = new InvestigationSourceStatus('runtime', 'failed', false, 0, 'Runtime inspection failed.');
            $missing[] = new InvestigationMissingEvidence('runtime_failed', 'runtime', 'Runtime inspection failed.');

            return [];
        }
    }

    /**
     * @param list<InvestigationSourceStatus> $statuses
     * @param list<InvestigationMissingEvidence> $missing
     *
     * @return array<string, scalar|null>
     */
    private function collectDatabase(array &$statuses, array &$missing): array
    {
        if (! $this->inspectors->enabled(DatabaseInspector::NAME)) {
            $statuses[] = new InvestigationSourceStatus('database', 'disabled', false, 0, 'Database inspection is disabled.');
            $missing[] = new InvestigationMissingEvidence('database_disabled', 'database', 'Database inspection is disabled.');

            return [];
        }

        try {
            /** @var DatabaseStatus $status */
            $status = $this->database->inspect([]);
            $statuses[] = new InvestigationSourceStatus('database', 'ready', false, 1, 'Database status was collected.');

            return [
                'connection' => $status->connection,
                'driver' => $status->driver,
                'status' => $status->status->value,
                'latency_ms' => $status->latencyMs,
            ];
        } catch (\Throwable) {
            $statuses[] = new InvestigationSourceStatus('database', 'failed', false, 0, 'Database status was unavailable.');
            $missing[] = new InvestigationMissingEvidence('database_unavailable', 'database', 'Database status was unavailable.');

            return [];
        }
    }

    /**
     * @param list<InvestigationSourceStatus> $statuses
     * @param list<InvestigationMissingEvidence> $missing
     */
    private function collectQueueStatus(array &$statuses, array &$missing): void
    {
        if (! $this->inspectors->enabled(QueueInspector::NAME)) {
            $statuses[] = new InvestigationSourceStatus('queue', 'disabled', false, 0, 'Queue inspection is disabled.');
            $missing[] = new InvestigationMissingEvidence('queue_disabled', 'queue', 'Queue inspection is disabled.');

            return;
        }

        try {
            $this->queue->inspect([]);
            $statuses[] = new InvestigationSourceStatus('queue', 'ready', false, 1, 'Queue status was observed.');
        } catch (\Throwable) {
            $statuses[] = new InvestigationSourceStatus('queue', 'failed', false, 0, 'Queue backend state is unavailable.');
            $missing[] = new InvestigationMissingEvidence('queue_unavailable', 'queue', 'Queue backend state is unavailable.');
        }
    }

    /**
     * @param list<InvestigationSourceStatus> $statuses
     * @param list<InvestigationMissingEvidence> $missing
     * @param list<string> $warnings
     */
    private function collectMigrationRisk(array &$statuses, array &$missing, array &$warnings): void
    {
        if (! $this->inspectors->enabled(MigrationRiskInspector::NAME)) {
            $statuses[] = new InvestigationSourceStatus('migration_risk', 'disabled', false, 0, 'Migration risk inspection is disabled.');
            $missing[] = new InvestigationMissingEvidence('migration_risk_disabled', 'migration_risk', 'Migration risk inspection is disabled.');

            return;
        }

        try {
            $inspection = $this->migrationRisk->inspect(['pending' => true, 'limit' => 10]);
            $findings = $inspection instanceof \Beacon\Data\MigrationRiskInspection ? $inspection->findings : [];
            $count = count($findings);
            $statuses[] = new InvestigationSourceStatus(
                'migration_risk',
                $count === 0 ? 'empty' : 'ready',
                false,
                $count,
                $count === 0 ? 'No pending migration risk findings were returned.' : 'Pending migration risk findings were observed.',
            );

            if ($count > 0) {
                $warnings[] = 'Pending migration risk findings were included as general context.';
            }
        } catch (\Throwable) {
            $statuses[] = new InvestigationSourceStatus('migration_risk', 'failed', false, 0, 'Migration risk analysis failed.');
            $missing[] = new InvestigationMissingEvidence('migration_risk_failed', 'migration_risk', 'Migration risk analysis failed.');
        }
    }

    /**
     * @param list<InvestigationMissingEvidence> $missing
     */
    private function recordUnavailableHistory(array &$missing): void
    {
        $missing[] = new InvestigationMissingEvidence(
            'deployment_history_unavailable',
            'deployment',
            'Deployment history is not configured.',
        );
        $missing[] = new InvestigationMissingEvidence(
            'configuration_change_history_unavailable',
            'configuration',
            'Configuration change history is not available.',
        );
    }

    /**
     * @param list<RequestObservation> $requests
     * @param list<ExceptionRecord> $exceptions
     * @param list<ErrorAggregate> $aggregates
     */
    private function resolveSubject(
        InvestigationScope $scope,
        array $requests,
        array $exceptions,
        array $aggregates,
    ): InvestigationSubject {
        $query = $scope->query;

        return match ($scope->subjectKind) {
            SubjectKind::Request => new InvestigationSubject(
                SubjectKind::Request,
                $query->requestId,
                $requests[0]->correlationId ?? $query->correlationId,
                $requests[0]->exceptionId ?? null,
                null,
                $requests[0]->routeUri ?? $query->endpoint,
                $requests === [] ? 'Requested request id was not found in available evidence.' : 'Primary subject is a recorded HTTP request.',
            ),
            SubjectKind::Correlation => new InvestigationSubject(
                SubjectKind::Correlation,
                $requests[0]->requestId ?? null,
                $query->correlationId,
                null,
                null,
                $requests[0]->routeUri ?? $query->endpoint,
                $requests === [] ? 'Requested correlation id was not found in available evidence.' : 'Primary subject is a correlation id across observations.',
            ),
            SubjectKind::Exception => new InvestigationSubject(
                SubjectKind::Exception,
                null,
                null,
                $query->exceptionId,
                $exceptions[0]->fingerprint ?? null,
                null,
                $exceptions === [] ? 'Requested exception id was not found in available evidence.' : 'Primary subject is a recorded exception occurrence.',
            ),
            SubjectKind::Fingerprint => new InvestigationSubject(
                SubjectKind::Fingerprint,
                null,
                null,
                null,
                $query->fingerprint,
                null,
                $aggregates === [] && $exceptions === []
                    ? 'Requested fingerprint was not found in available evidence.'
                    : 'Primary subject is a recurring exception fingerprint.',
            ),
            SubjectKind::Endpoint => new InvestigationSubject(
                SubjectKind::Endpoint,
                null,
                null,
                null,
                null,
                $query->endpoint,
                'Primary subject is an endpoint over a bounded time window.',
            ),
            SubjectKind::TimeWindow, SubjectKind::Insufficient => new InvestigationSubject(
                SubjectKind::TimeWindow,
                null,
                null,
                null,
                null,
                null,
                'Primary subject is recent application activity in a bounded time window.',
            ),
        };
    }

    /**
     * @param list<RequestObservation> $requests
     * @param list<ExceptionRecord> $exceptions
     * @param list<LogRecord> $logs
     * @param list<FailedJobRecord> $jobs
     * @param list<RequestObservation> $slow
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function actualWindow(array $requests, array $exceptions, array $logs, array $jobs, array $slow): array
    {
        $timestamps = [];

        foreach ($requests as $request) {
            $timestamps[] = $request->startedAt;
        }

        foreach ($slow as $request) {
            $timestamps[] = $request->startedAt;
        }

        foreach ($exceptions as $exception) {
            $timestamps[] = $exception->recordedAt;
        }

        foreach ($logs as $log) {
            $timestamps[] = $log->timestamp;
        }

        foreach ($jobs as $job) {
            if ($job->failedAt !== null) {
                $timestamps[] = $job->failedAt;
            }
        }

        if ($timestamps === []) {
            return [null, null];
        }

        sort($timestamps);

        return [$timestamps[0], $timestamps[array_key_last($timestamps)]];
    }

    private function withinWindow(string $timestamp, string $since, string $until): bool
    {
        try {
            $value = (new \DateTimeImmutable($timestamp))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.vP');
        } catch (\Throwable) {
            return false;
        }

        return $value >= $since && $value <= $until;
    }
}
