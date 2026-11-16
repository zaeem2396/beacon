<?php

declare(strict_types=1);

namespace Beacon\Investigation;

use Beacon\Data\ErrorAggregate;
use Beacon\Data\ExceptionRecord;
use Beacon\Data\FailedJobRecord;
use Beacon\Data\InvestigationMissingEvidence;
use Beacon\Data\InvestigationSourceStatus;
use Beacon\Data\InvestigationSubject;
use Beacon\Logs\LogRecord;
use Beacon\Requests\RequestObservation;

/**
 * Raw evidence gathered for one investigation before correlation.
 */
final class CollectedEvidence
{
    /**
     * @param list<ExceptionRecord> $exceptions
     * @param list<ErrorAggregate> $aggregates
     * @param list<RequestObservation> $requests
     * @param list<RequestObservation> $slowRequests
     * @param list<LogRecord> $logs
     * @param list<FailedJobRecord> $failedJobs
     * @param list<InvestigationSourceStatus> $sourceStatuses
     * @param list<InvestigationMissingEvidence> $missing
     * @param array<string, scalar|null> $runtimeFacts
     * @param array<string, scalar|null> $databaseFacts
     * @param list<string> $warnings
     */
    public function __construct(
        public InvestigationSubject $subject,
        public array $exceptions = [],
        public array $aggregates = [],
        public array $requests = [],
        public array $slowRequests = [],
        public array $logs = [],
        public array $failedJobs = [],
        public array $sourceStatuses = [],
        public array $missing = [],
        public array $runtimeFacts = [],
        public array $databaseFacts = [],
        public array $warnings = [],
        public ?string $actualWindowStart = null,
        public ?string $actualWindowEnd = null,
        public bool $truncated = false,
    ) {
    }
}
