<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Requests;

use Beacon\Requests\RequestObservation;
use Beacon\Requests\SlowRank;
use Beacon\Requests\SlowRequestDetector;
use Beacon\Requests\SlowThresholds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SlowRequestDetectorTest extends TestCase
{
    #[Test]
    public function threshold_equality_is_not_slow(): void
    {
        $detector = new SlowRequestDetector();
        $thresholds = new SlowThresholds(1000, []);
        $records = [
            $this->observation('request-id-equal-0001', 1000, '2026-11-14T12:00:00.000+00:00'),
            $this->observation('request-id-under-0002', 999, '2026-11-14T12:00:01.000+00:00'),
        ];

        $this->assertSame([], $detector->detect($records, $thresholds));
    }

    #[Test]
    public function excess_ranks_follow_multiples_of_the_threshold(): void
    {
        $detector = new SlowRequestDetector();
        $thresholds = new SlowThresholds(1000, []);
        $records = [
            $this->observation('request-id-low----0001', 1500, '2026-11-14T12:00:00.000+00:00'),
            $this->observation('request-id-medium-0002', 3000, '2026-11-14T12:00:01.000+00:00'),
            $this->observation('request-id-high---0003', 5000, '2026-11-14T12:00:02.000+00:00'),
        ];

        $slow = $detector->detect($records, $thresholds);

        $this->assertCount(3, $slow);
        $this->assertSame(SlowRank::High, $slow[0]->rank);
        $this->assertSame(4000, $slow[0]->excessMs);
        $this->assertSame(SlowRank::Medium, $slow[1]->rank);
        $this->assertSame(2000, $slow[1]->excessMs);
        $this->assertSame(SlowRank::Low, $slow[2]->rank);
        $this->assertSame(500, $slow[2]->excessMs);
    }

    #[Test]
    public function results_are_sorted_by_duration_then_started_at(): void
    {
        $detector = new SlowRequestDetector();
        $thresholds = new SlowThresholds(100, []);
        $records = [
            $this->observation('request-id-older--0001', 500, '2026-11-14T12:00:00.000+00:00'),
            $this->observation('request-id-newer--0002', 500, '2026-11-14T12:00:02.000+00:00'),
            $this->observation('request-id-fastest-0003', 200, '2026-11-14T12:00:03.000+00:00'),
        ];

        $slow = $detector->detect($records, $thresholds);

        $this->assertSame(
            ['request-id-newer--0002', 'request-id-older--0001', 'request-id-fastest-0003'],
            array_map(static fn ($item): string => $item->observation->requestId, $slow),
        );
    }

    private function observation(string $id, int $durationMs, string $startedAt): RequestObservation
    {
        return new RequestObservation(
            requestId: $id,
            correlationId: $id,
            method: 'GET',
            routeName: 'probe.show',
            routeUri: '/probe',
            action: 'Closure',
            status: 200,
            startedAt: $startedAt,
            finishedAt: $startedAt,
            durationMs: $durationMs,
            environment: 'testing',
            hadException: false,
            exceptionId: null,
            exceptionClass: null,
        );
    }
}
