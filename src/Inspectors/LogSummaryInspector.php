<?php

declare(strict_types=1);

namespace Beacon\Inspectors;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Data\LogSummaryInspection;
use Beacon\Exceptions\InvalidLogQueryException;
use Beacon\Health\SafeName;
use Beacon\Logs\LogLevel;
use Beacon\Logs\LogReader;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;

/**
 * Summary metrics for supported file-based log channels.
 *
 * @implements Inspector<LogSummaryInspection>
 */
final class LogSummaryInspector implements Inspector
{
    public const NAME = 'logs';

    public function __construct(
        private readonly LogReader $reader,
    ) {
    }

    public function capability(): Capability
    {
        return InspectionCapability::Logs->capability();
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function inspect(array $parameters = []): Data
    {
        $channel = null;
        $limit = LogReader::DEFAULT_LIMIT;

        foreach ($parameters as $key => $value) {
            if ($key === 'channel') {
                if ($value !== null && (! is_string($value) || ! SafeName::isIdentifier($value))) {
                    throw InvalidLogQueryException::channel();
                }

                $channel = is_string($value) ? $value : null;
            } elseif ($key === 'limit') {
                if (is_int($value)) {
                    $limit = $value;
                } elseif (is_string($value) && ctype_digit($value)) {
                    $limit = (int) $value;
                } else {
                    throw InvalidLogQueryException::filter('Log summary rejected the limit.');
                }

                if ($limit < 1 || $limit > LogReader::MAX_LIMIT) {
                    throw InvalidLogQueryException::filter('Log summary rejected the limit.');
                }
            } else {
                throw InvalidLogQueryException::filter('Log summary rejected an unknown parameter.');
            }
        }

        try {
            $read = $this->reader->read($channel, $limit);
        } catch (InvalidLogQueryException $exception) {
            throw $exception;
        } catch (\Throwable) {
            return new LogSummaryInspection(
                inspected: 0,
                errorCount: 0,
                criticalCount: 0,
                byLevel: [],
                byChannel: [],
                windowStart: null,
                windowEnd: null,
                parseFailures: 0,
                truncated: false,
                summary: 'Log sources were unavailable.',
                warnings: ['Supported file log channels could not be read.'],
            );
        }

        $byLevel = [];
        $byChannel = [];
        $errors = 0;
        $critical = 0;
        $start = null;
        $end = null;

        foreach ($read['records'] as $record) {
            $byLevel[$record->level->value] = ($byLevel[$record->level->value] ?? 0) + 1;
            $byChannel[$record->channel] = ($byChannel[$record->channel] ?? 0) + 1;
            $start = $start === null ? $record->timestamp : min($start, $record->timestamp);
            $end = $end === null ? $record->timestamp : max($end, $record->timestamp);

            if ($record->level === LogLevel::Error) {
                $errors++;
            }

            if ($record->level->rank() >= LogLevel::Critical->rank()) {
                $critical++;
            }
        }

        $warnings = [];

        if ($read['truncated']) {
            $warnings[] = 'Log reading stopped at the configured byte or record limit.';
        }

        if ($read['files'] === 0) {
            $warnings[] = 'No supported file log channels were readable.';
        }

        return new LogSummaryInspection(
            inspected: count($read['records']),
            errorCount: $errors,
            criticalCount: $critical,
            byLevel: $byLevel,
            byChannel: $byChannel,
            windowStart: $start,
            windowEnd: $end,
            parseFailures: $read['parse_failures'],
            truncated: $read['truncated'],
            summary: count($read['records']) === 0
                ? 'No log records were found in the inspected window.'
                : 'Log records were summarized from supported file channels.',
            warnings: $warnings,
        );
    }
}
