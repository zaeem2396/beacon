<?php

declare(strict_types=1);

namespace Beacon\Logs;

use Beacon\Data\ErrorAggregate;
use Beacon\Data\ErrorSummary;
use Beacon\Data\ExceptionRecord;

/**
 * Groups normalized exception records for a single inspected window.
 */
final class ExceptionAggregator
{
    /**
     * @param list<ExceptionRecord> $records
     *
     * @return array{groups: list<ErrorAggregate>, summary: ErrorSummary}
     */
    public function aggregate(array $records, AggregateQuery $query, bool $truncated, int $inspected): array
    {
        /** @var array<string, array{record: ExceptionRecord, count: int, first: string, last: string, channels: array<string, true>, files: array<string, true>}> $groups */
        $groups = [];

        foreach ($records as $record) {
            $existing = $groups[$record->fingerprint] ?? null;

            if ($existing === null) {
                $groups[$record->fingerprint] = [
                    'record' => $record,
                    'count' => 1,
                    'first' => $record->recordedAt,
                    'last' => $record->recordedAt,
                    'channels' => [$record->channel => true],
                    'files' => $record->file !== null ? [$record->file => true] : [],
                ];

                continue;
            }

            $existing['count']++;
            $existing['first'] = min($existing['first'], $record->recordedAt);
            $existing['last'] = max($existing['last'], $record->recordedAt);
            $existing['channels'][$record->channel] = true;

            if ($record->file !== null) {
                $existing['files'][$record->file] = true;
            }

            if ($record->severity->rank() > $existing['record']->severity->rank()) {
                $existing['record'] = $record;
            }

            $groups[$record->fingerprint] = $existing;
        }

        $aggregates = [];

        foreach ($groups as $fingerprint => $group) {
            if ($group['count'] < $query->minCount) {
                continue;
            }

            if ($query->rejects($group['record'])) {
                continue;
            }

            $record = $group['record'];
            $aggregates[] = new ErrorAggregate(
                fingerprint: $fingerprint,
                className: $record->className,
                message: $record->message,
                category: $record->category,
                severity: $record->severity,
                occurrences: $group['count'],
                firstSeenAt: $group['first'],
                lastSeenAt: $group['last'],
                channels: array_keys($group['channels']),
                files: array_keys($group['files']),
                tracePresent: $record->tracePresent,
                traceTruncated: $record->traceTruncated,
            );
        }

        usort($aggregates, static function (ErrorAggregate $a, ErrorAggregate $b) use ($query): int {
            if ($query->sort === 'last_seen') {
                return $b->lastSeenAt <=> $a->lastSeenAt ?: $b->occurrences <=> $a->occurrences;
            }

            return $b->occurrences <=> $a->occurrences ?: $b->lastSeenAt <=> $a->lastSeenAt;
        });

        $totalGroups = count($aggregates);
        $offset = ($query->page - 1) * $query->limit;
        $page = array_slice($aggregates, $offset, $query->limit);
        $windowStart = null;
        $windowEnd = null;

        foreach ($records as $record) {
            $windowStart = $windowStart === null ? $record->recordedAt : min($windowStart, $record->recordedAt);
            $windowEnd = $windowEnd === null ? $record->recordedAt : max($windowEnd, $record->recordedAt);
        }

        return [
            'groups' => $page,
            'summary' => new ErrorSummary(
                inspectedRecords: $inspected,
                matchedRecords: count($records),
                uniqueGroups: $totalGroups,
                returnedGroups: count($page),
                bySeverity: $this->countBy($records, static fn (ExceptionRecord $record): string => $record->severity->value),
                byCategory: $this->countBy($records, static fn (ExceptionRecord $record): string => $record->category->value),
                byClass: $this->countBy($records, static fn (ExceptionRecord $record): string => $record->className),
                windowStart: $windowStart,
                windowEnd: $windowEnd,
                truncated: $truncated || $totalGroups > count($page),
                limitation: $truncated
                    ? 'Aggregation covers only the inspected log window.'
                    : 'Aggregation covers only the inspected log records.',
            ),
        ];
    }

    /**
     * @param list<ExceptionRecord> $records
     * @param callable(ExceptionRecord): string $pick
     *
     * @return array<string, int>
     */
    private function countBy(array $records, callable $pick): array
    {
        $counts = [];

        foreach ($records as $record) {
            $key = $pick($record);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }
}
