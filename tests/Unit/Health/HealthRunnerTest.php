<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Health;

use Beacon\Data\HealthCheckResult;
use Beacon\Health\HealthRunner;
use Beacon\Health\HealthStatus;
use Beacon\Tests\Fixtures\BrokenHealthCheck;
use Beacon\Tests\Fixtures\FrozenClock;
use Beacon\Tests\Fixtures\StaticHealthCheck;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HealthRunnerTest extends TestCase
{
    private const DESCRIPTION = 'A health check.';

    #[Test]
    public function a_failed_check_does_not_stop_the_checks_after_it(): void
    {
        $secret = 'mysql://beacon:health-secret-not-in-output@db.internal/app';
        $report = $this->runner([
            new StaticHealthCheck('database', self::DESCRIPTION, new \RuntimeException($secret)),
            new StaticHealthCheck('application', self::DESCRIPTION, $this->checkResult('application', HealthStatus::Healthy)),
        ]);

        $encoded = json_encode($report->toArray());
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('health-secret-not-in-output', $encoded);
        $this->assertStringNotContainsString('db.internal', $encoded);
        $this->assertSame(['database', 'application'], array_map(
            static fn (HealthCheckResult $result): string => $result->name,
            $report->checks,
        ));
        $this->assertSame(HealthStatus::Unknown, $report->checks[0]->status);
        $this->assertSame('The check could not be completed.', $report->checks[0]->summary);
        $this->assertSame(HealthStatus::Healthy, $report->checks[1]->status);
        $this->assertSame(HealthStatus::Unknown, $report->status);
        $this->assertIsInt($report->checks[0]->durationMs);
        $this->assertIsInt($report->checks[1]->durationMs);
    }

    #[Test]
    public function a_later_confirmed_failure_still_wins_when_an_earlier_check_throws(): void
    {
        $report = $this->runner([
            new StaticHealthCheck('cache', self::DESCRIPTION, new \RuntimeException('cache-secret-not-in-output')),
            new StaticHealthCheck('database', self::DESCRIPTION, $this->checkResult('database', HealthStatus::Critical)),
        ]);

        $this->assertSame(HealthStatus::Critical, $report->status);
        $this->assertSame(HealthStatus::Unknown, $report->checks[0]->status);
        $this->assertSame(HealthStatus::Critical, $report->checks[1]->status);
        $this->assertStringNotContainsString('cache-secret-not-in-output', (string) json_encode($report->toArray()));
    }

    #[Test]
    public function a_result_for_another_check_is_replaced_and_the_runner_continues(): void
    {
        $report = $this->runner([
            new StaticHealthCheck('database', self::DESCRIPTION, $this->checkResult('queue', HealthStatus::Healthy)),
            new StaticHealthCheck('application', self::DESCRIPTION, $this->checkResult('application', HealthStatus::Healthy)),
        ]);

        $this->assertSame('database', $report->checks[0]->name);
        $this->assertSame(HealthStatus::Unknown, $report->checks[0]->status);
        $this->assertSame(HealthStatus::Healthy, $report->checks[1]->status);
    }

    #[Test]
    public function a_check_that_cannot_name_itself_does_not_leak_the_error(): void
    {
        $report = (new HealthRunner(
            [new BrokenHealthCheck(), new StaticHealthCheck('application', self::DESCRIPTION, $this->checkResult('application', HealthStatus::Skipped))],
            new FrozenClock('2026-10-25T13:33:00+00:00'),
        ))->run();

        $encoded = (string) json_encode($report->toArray());
        $this->assertSame('health_check', $report->checks[0]->name);
        $this->assertSame(HealthStatus::Unknown, $report->checks[0]->status);
        $this->assertSame(HealthStatus::Skipped, $report->checks[1]->status);
        $this->assertStringNotContainsString('name-secret-not-in-output', $encoded);
        $this->assertStringNotContainsString('description-secret-not-in-output', $encoded);
        $this->assertStringNotContainsString('check-secret-not-in-output', $encoded);
    }

    #[Test]
    public function the_assessment_time_is_utc(): void
    {
        $report = $this->runner(
            [new StaticHealthCheck('application', self::DESCRIPTION, $this->checkResult('application', HealthStatus::Healthy))],
            '2026-10-25T19:03:00+05:30',
        );

        $this->assertSame('2026-10-25T13:33:00+00:00', $report->assessedAt);
    }

    private function checkResult(string $name, HealthStatus $status): HealthCheckResult
    {
        return new HealthCheckResult(
            $name,
            self::DESCRIPTION,
            $status,
            'The check finished.',
            null,
            $status === HealthStatus::Healthy ? null : 'Run the check again.',
            [],
        );
    }

    /**
     * @param list<\Beacon\Contracts\HealthCheck> $checks
     */
    private function runner(array $checks, string $moment = '2026-10-25T13:33:00+00:00'): \Beacon\Data\HealthInspection
    {
        return (new HealthRunner($checks, new FrozenClock($moment)))->run();
    }
}
