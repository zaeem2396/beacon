<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Schema\Prose;

/**
 * Normalized, versioned AI investigation context.
 *
 * Beacon collects and correlates evidence. It does not claim a root cause.
 */
final readonly class InvestigationContext extends DataObject
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    /**
     * @param list<InvestigationSourceStatus> $sourceStatuses
     * @param list<InvestigationEvidenceItem> $exceptions
     * @param list<InvestigationEvidenceItem> $errorPatterns
     * @param list<InvestigationEvidenceItem> $requests
     * @param list<InvestigationEvidenceItem> $slowRequests
     * @param list<InvestigationEvidenceItem> $logs
     * @param list<InvestigationEvidenceItem> $failedJobs
     * @param list<InvestigationEvidenceItem> $runtime
     * @param list<InvestigationEvidenceItem> $database
     * @param list<InvestigationCorrelation> $correlations
     * @param list<InvestigationTimelineEvent> $timeline
     * @param list<InvestigationLead> $leads
     * @param list<InvestigationMissingEvidence> $missingEvidence
     * @param list<string> $warnings
     * @param array<string, scalar|null> $scope
     */
    public function __construct(
        public string $investigationId,
        public string $generatedAt,
        public ?string $applicationName,
        public ?string $environment,
        public array $scope,
        public string $requestedWindowStart,
        public string $requestedWindowEnd,
        public ?string $actualWindowStart,
        public ?string $actualWindowEnd,
        public string $completeness,
        public bool $truncated,
        public array $sourceStatuses,
        public InvestigationSubject $primarySubject,
        public array $exceptions,
        public array $errorPatterns,
        public array $requests,
        public array $slowRequests,
        public array $logs,
        public array $failedJobs,
        public array $runtime,
        public array $database,
        public array $correlations,
        public array $timeline,
        public array $leads,
        public array $missingEvidence,
        public string $status,
        public string $summary,
        public array $warnings,
    ) {
        if (! Prose::isSafe($this->completeness)
            || ! Prose::isSafe($this->status)
            || ! Prose::isSafe($this->summary)) {
            throw new \InvalidArgumentException('Investigation context prose must be safe.');
        }

        foreach ($this->warnings as $warning) {
            if (! is_string($warning) || ! Prose::isSafe($warning)) {
                throw new \InvalidArgumentException('Investigation warnings must be safe prose.');
            }
        }

        $this->schemaVersion = self::SCHEMA_VERSION;
    }
}
