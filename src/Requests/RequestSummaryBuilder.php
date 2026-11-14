<?php

declare(strict_types=1);

namespace Beacon\Requests;

use Beacon\Data\RequestRecord;
use Beacon\Data\RequestSummary;

/**
 * Builds window-scoped request metrics without inventing missing values.
 */
final class RequestSummaryBuilder
{
    /**
     * @param list<RequestObservation> $records
     */
    public function build(
        array $records,
        int $returned,
        int $total,
        bool $truncated,
        string $collection,
        string $limitation,
    ): RequestSummary {
        /** @var array<array-key, int> $byStatus */
        $byStatus = [];
        /** @var array<string, int> $byMethod */
        $byMethod = [];
        /** @var array<string, int> $byRoute */
        $byRoute = [];
        $durations = [];
        $withExceptions = 0;
        $start = null;
        $end = null;

        foreach ($records as $record) {
            if ($record->status !== null) {
                $status = $record->status;
                $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            }

            $byMethod[$record->method] = ($byMethod[$record->method] ?? 0) + 1;
            $route = $record->routeName ?? $record->routeUri;
            $byRoute[$route] = ($byRoute[$route] ?? 0) + 1;

            if ($record->hadException) {
                $withExceptions++;
            }

            if ($record->durationMs !== null) {
                $durations[] = $record->durationMs;
            }

            $start = $start === null ? $record->startedAt : min($start, $record->startedAt);
            $end = $end === null ? $record->startedAt : max($end, $record->startedAt);
        }

        arsort($byStatus);
        arsort($byMethod);
        arsort($byRoute);

        return new RequestSummary(
            inspected: $total,
            returned: $returned,
            withExceptions: $withExceptions,
            byStatus: $byStatus,
            byMethod: $byMethod,
            byRoute: array_slice($byRoute, 0, 25, true),
            averageMs: $this->average($durations),
            medianMs: $this->percentile($durations, 50),
            p95Ms: count($durations) >= 5 ? $this->percentile($durations, 95) : null,
            windowStart: $start,
            windowEnd: $end,
            truncated: $truncated,
            collection: $collection,
            limitation: $limitation,
        );
    }

    public function toRecord(RequestObservation $observation): RequestRecord
    {
        return new RequestRecord(
            requestId: $observation->requestId,
            correlationId: $observation->correlationId,
            method: $observation->method,
            routeName: $observation->routeName,
            routeUri: $observation->routeUri,
            action: $observation->action,
            status: $observation->status,
            startedAt: $observation->startedAt,
            finishedAt: $observation->finishedAt,
            durationMs: $observation->durationMs,
            environment: $observation->environment,
            requestType: 'http',
            hadException: $observation->hadException,
            exceptionId: $observation->exceptionId,
            exceptionClass: $observation->exceptionClass,
        );
    }

    /**
     * @param list<int> $values
     */
    private function average(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 2);
    }

    /**
     * @param list<int> $values
     */
    private function percentile(array $values, int $percentile): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $index = (int) ceil(($percentile / 100) * count($values)) - 1;
        $index = max(0, min(count($values) - 1, $index));

        return (float) $values[$index];
    }
}
