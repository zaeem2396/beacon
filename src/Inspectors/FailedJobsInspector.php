<?php

declare(strict_types=1);

namespace Beacon\Inspectors;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Data\FailedJobInspection;
use Beacon\Exceptions\InvalidLogQueryException;
use Beacon\QueueIntelligence\FailedJobReader;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;

/**
 * Failed jobs without payloads or job execution.
 *
 * @implements Inspector<FailedJobInspection>
 */
final class FailedJobsInspector implements Inspector
{
    public const NAME = 'failed_jobs';

    public function __construct(
        private readonly FailedJobReader $reader,
    ) {
    }

    public function capability(): Capability
    {
        return InspectionCapability::FailedJobs->capability();
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function inspect(array $parameters = []): Data
    {
        $limit = FailedJobReader::DEFAULT_LIMIT;

        foreach ($parameters as $key => $value) {
            if ($key !== 'limit') {
                throw InvalidLogQueryException::filter('Failed job inspection rejected an unknown parameter.');
            }

            if (is_int($value)) {
                $limit = $value;
            } elseif (is_string($value) && ctype_digit($value)) {
                $limit = (int) $value;
            } else {
                throw InvalidLogQueryException::filter('Failed job inspection rejected the limit.');
            }

            if ($limit < 1 || $limit > FailedJobReader::MAX_LIMIT) {
                throw InvalidLogQueryException::filter('Failed job inspection rejected the limit.');
            }
        }

        $read = $this->reader->read($limit);
        $warnings = [];

        if ($read['warning'] !== null) {
            $warnings[] = $read['warning'];
        }

        return new FailedJobInspection(
            jobs: $read['jobs'],
            returned: count($read['jobs']),
            total: $read['total'],
            truncated: $read['truncated'],
            driver: $read['driver'],
            summary: count($read['jobs']) === 0
                ? 'No failed jobs were returned.'
                : 'Failed jobs were read without payloads.',
            warnings: $warnings,
        );
    }
}
