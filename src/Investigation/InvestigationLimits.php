<?php

declare(strict_types=1);

namespace Beacon\Investigation;

/**
 * Bounded output limits for a single investigation context build.
 */
final readonly class InvestigationLimits
{
    public const DEFAULT_WINDOW_MINUTES = 60;

    public const MAX_WINDOW_MINUTES = 10080;

    public const DEFAULT_EXCEPTIONS = 25;

    public const DEFAULT_EXCEPTION_SAMPLES = 5;

    public const DEFAULT_REQUESTS = 25;

    public const DEFAULT_SLOW_REQUESTS = 25;

    public const DEFAULT_LOGS = 25;

    public const DEFAULT_FAILED_JOBS = 25;

    public const DEFAULT_TIMELINE = 50;

    public const DEFAULT_LEADS = 10;

    public const DEFAULT_TOTAL_EVIDENCE = 150;

    public const MAX_MESSAGE_LENGTH = 240;

    public const MAX_STACK_FRAMES = 5;

    public function __construct(
        public int $windowMinutes,
        public int $maxExceptions,
        public int $maxExceptionSamples,
        public int $maxRequests,
        public int $maxSlowRequests,
        public int $maxLogs,
        public int $maxFailedJobs,
        public int $maxTimelineEvents,
        public int $maxLeads,
        public int $maxMessageLength,
        public int $maxStackFrames,
        public int $maxTotalEvidence,
    ) {
    }

    public static function defaults(): self
    {
        return new self(
            windowMinutes: self::DEFAULT_WINDOW_MINUTES,
            maxExceptions: self::DEFAULT_EXCEPTIONS,
            maxExceptionSamples: self::DEFAULT_EXCEPTION_SAMPLES,
            maxRequests: self::DEFAULT_REQUESTS,
            maxSlowRequests: self::DEFAULT_SLOW_REQUESTS,
            maxLogs: self::DEFAULT_LOGS,
            maxFailedJobs: self::DEFAULT_FAILED_JOBS,
            maxTimelineEvents: self::DEFAULT_TIMELINE,
            maxLeads: self::DEFAULT_LEADS,
            maxMessageLength: self::MAX_MESSAGE_LENGTH,
            maxStackFrames: self::MAX_STACK_FRAMES,
            maxTotalEvidence: self::DEFAULT_TOTAL_EVIDENCE,
        );
    }
}
