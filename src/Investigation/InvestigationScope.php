<?php

declare(strict_types=1);

namespace Beacon\Investigation;

/**
 * Normalized investigation scope after applying defaults and limits.
 */
final readonly class InvestigationScope
{
    public function __construct(
        public InvestigationQuery $query,
        public SubjectKind $subjectKind,
        public string $requestedSince,
        public string $requestedUntil,
        public InvestigationLimits $limits,
        public bool $usedDefaultWindow,
    ) {
    }

    public static function from(InvestigationQuery $query, InvestigationLimits $limits): self
    {
        $windowMinutes = $query->windowMinutes ?? $limits->windowMinutes;
        $until = $query->until ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.vP');
        $usedDefault = $query->since === null && $query->until === null;
        $since = $query->since;

        if ($since === null) {
            $since = (new \DateTimeImmutable($until))
                ->modify(sprintf('-%d minutes', $windowMinutes))
                ->format('Y-m-d\\TH:i:s.vP');
        }

        $spanMinutes = ((new \DateTimeImmutable($until))->getTimestamp() - (new \DateTimeImmutable($since))->getTimestamp()) / 60;

        if ($spanMinutes > InvestigationLimits::MAX_WINDOW_MINUTES) {
            throw \Beacon\Exceptions\InvalidInvestigationQueryException::filter(
                'Investigation rejected a time window larger than the configured maximum.',
            );
        }

        $kind = match (true) {
            $query->requestId !== null => SubjectKind::Request,
            $query->correlationId !== null => SubjectKind::Correlation,
            $query->exceptionId !== null => SubjectKind::Exception,
            $query->fingerprint !== null => SubjectKind::Fingerprint,
            $query->endpoint !== null => SubjectKind::Endpoint,
            default => SubjectKind::TimeWindow,
        };

        $adjusted = $limits;

        if ($query->maxResults !== null) {
            $max = $query->maxResults;
            $adjusted = new InvestigationLimits(
                windowMinutes: $windowMinutes,
                maxExceptions: min($limits->maxExceptions, $max),
                maxExceptionSamples: min($limits->maxExceptionSamples, $max),
                maxRequests: min($limits->maxRequests, $max),
                maxSlowRequests: min($limits->maxSlowRequests, $max),
                maxLogs: min($limits->maxLogs, $max),
                maxFailedJobs: min($limits->maxFailedJobs, $max),
                maxTimelineEvents: min($limits->maxTimelineEvents, max(1, $max * 2)),
                maxLeads: min($limits->maxLeads, max(1, intdiv($max, 2))),
                maxMessageLength: $limits->maxMessageLength,
                maxStackFrames: $limits->maxStackFrames,
                maxTotalEvidence: min($limits->maxTotalEvidence, max(1, $max * 6)),
            );
        } else {
            $adjusted = new InvestigationLimits(
                windowMinutes: $windowMinutes,
                maxExceptions: $limits->maxExceptions,
                maxExceptionSamples: $limits->maxExceptionSamples,
                maxRequests: $limits->maxRequests,
                maxSlowRequests: $limits->maxSlowRequests,
                maxLogs: $limits->maxLogs,
                maxFailedJobs: $limits->maxFailedJobs,
                maxTimelineEvents: $limits->maxTimelineEvents,
                maxLeads: $limits->maxLeads,
                maxMessageLength: $limits->maxMessageLength,
                maxStackFrames: $limits->maxStackFrames,
                maxTotalEvidence: $limits->maxTotalEvidence,
            );
        }

        return new self($query, $kind, $since, $until, $adjusted, $usedDefault);
    }
}
