<?php

declare(strict_types=1);

namespace Beacon\Requests;

/**
 * Classifies recorded observations as slow using configured thresholds.
 */
final class SlowRequestDetector
{
    /**
     * @param list<RequestObservation> $records
     *
     * @return list<SlowRequest>
     */
    public function detect(array $records, SlowThresholds $thresholds): array
    {
        $slow = [];

        foreach ($records as $record) {
            if ($record->durationMs === null) {
                continue;
            }

            $threshold = $thresholds->for($record);

            if ($record->durationMs <= $threshold) {
                continue;
            }

            $slow[] = new SlowRequest(
                $record,
                $threshold,
                $record->durationMs - $threshold,
                SlowRank::fromExcess($record->durationMs, $threshold),
            );
        }

        usort(
            $slow,
            static function (SlowRequest $left, SlowRequest $right): int {
                $byDuration = $right->observation->durationMs <=> $left->observation->durationMs;

                return $byDuration !== 0 ? $byDuration : $right->observation->startedAt <=> $left->observation->startedAt;
            },
        );

        return $slow;
    }
}
