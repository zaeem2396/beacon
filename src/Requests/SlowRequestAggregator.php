<?php

declare(strict_types=1);

namespace Beacon\Requests;

/**
 * Groups request observations by method and normalized endpoint.
 */
final class SlowRequestAggregator
{
    /**
     * @param list<RequestObservation> $records
     *
     * @return list<SlowEndpointSummary>
     */
    public function summarize(array $records, SlowThresholds $thresholds, int $minimumSamples, int $maxEndpoints): array
    {
        /** @var array<string, list<RequestObservation>> $groups */
        $groups = [];

        foreach ($records as $record) {
            $endpoint = $record->routeName ?? $record->routeUri;
            $key = $record->method . "\n" . $endpoint;
            $groups[$key][] = $record;
        }

        $summaries = [];

        foreach ($groups as $members) {
            $first = $members[0];
            $endpoint = $first->routeName ?? $first->routeUri;
            $durations = [];
            $slow = 0;
            $exceptions = 0;
            /** @var array<array-key, int> $byStatus */
            $byStatus = [];
            $threshold = $thresholds->for($first);

            foreach ($members as $member) {
                $threshold = min($threshold, $thresholds->for($member));

                if ($member->durationMs !== null) {
                    $durations[] = $member->durationMs;

                    if ($member->durationMs > $thresholds->for($member)) {
                        $slow++;
                    }
                }

                if ($member->hadException) {
                    $exceptions++;
                }

                if ($member->status !== null) {
                    $status = (string) $member->status;
                    $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
                }
            }

            arsort($byStatus);
            $samples = count($members);
            $summaries[] = new SlowEndpointSummary(
                method: $first->method,
                endpoint: $endpoint,
                thresholdMs: $threshold,
                samples: $samples,
                slowCount: $slow,
                slowPercent: $samples === 0 ? 0.0 : round(($slow / $samples) * 100, 2),
                averageMs: DurationStatistics::average($durations),
                medianMs: DurationStatistics::median($durations),
                p95Ms: DurationStatistics::p95($durations),
                p99Ms: DurationStatistics::p99($durations),
                maximumMs: DurationStatistics::maximum($durations),
                byStatus: $byStatus,
                exceptionCount: $exceptions,
                sufficientSamples: $samples >= $minimumSamples,
            );
        }

        usort(
            $summaries,
            static function (SlowEndpointSummary $left, SlowEndpointSummary $right): int {
                $bySlow = $right->slowCount <=> $left->slowCount;

                if ($bySlow !== 0) {
                    return $bySlow;
                }

                return ($right->maximumMs ?? 0) <=> ($left->maximumMs ?? 0);
            },
        );

        return array_slice($summaries, 0, max(1, $maxEndpoints));
    }
}
