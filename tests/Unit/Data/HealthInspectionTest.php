<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Data;

use Beacon\Data\FindingEvidence;
use Beacon\Data\HealthCheckResult;
use Beacon\Data\HealthCounts;
use Beacon\Data\HealthInspection;
use Beacon\Data\Observation;
use Beacon\Health\HealthStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HealthInspectionTest extends TestCase
{
    private const ASSESSED_AT = '2026-10-25T13:33:00+00:00';

    #[Test]
    public function a_report_serializes_in_declaration_order(): void
    {
        $report = $this->report([$this->check('application', HealthStatus::Healthy)]);

        $this->assertSame([
            'schema_version',
            'status',
            'summary',
            'assessed_at',
            'counts',
            'checks',
        ], array_keys($report->toArray()));
        $this->assertSame([
            'name',
            'description',
            'status',
            'summary',
            'duration_ms',
            'remediation',
            'evidence',
        ], array_keys($report->checks[0]->toArray()));
        $this->assertNull($report->checks[0]->durationMs);
        $this->assertNull($report->checks[0]->remediation);
        $this->assertSame('healthy', $report->toArray()['status']);
        $this->assertSame(1, $report->schemaVersion);
    }

    #[Test]
    public function a_healthy_check_cannot_carry_remediation(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HealthCheckResult(
            'application',
            'A health check.',
            HealthStatus::Healthy,
            'The check finished.',
            null,
            'Check the application.',
            [],
        );
    }

    #[Test]
    public function prose_rejects_a_path_or_address(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HealthCheckResult(
            'database',
            'A read-only connectivity probe of the default database connection, when enabled.',
            HealthStatus::Critical,
            'Failed at mysql://db.internal/app.',
            null,
            'Check the default database.',
            [],
        );
    }

    #[Test]
    public function counts_must_add_up(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HealthCounts(3, 1, 0, 0, 0, 0);
    }

    #[Test]
    public function the_report_rejects_a_status_that_disagrees_with_the_checks(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $checks = [$this->check('database', HealthStatus::Critical)];
        $counts = HealthCounts::fromChecks($checks);

        new HealthInspection(HealthStatus::Healthy, 'Every completed health check passed.', self::ASSESSED_AT, $counts, $checks);
    }

    /**
     * @param list<HealthStatus> $statuses
     */
    #[Test]
    #[DataProvider('aggregation')]
    public function overall_status_follows_the_worst_completed_check(array $statuses, HealthStatus $overall, string $summary): void
    {
        $checks = [];

        foreach ($statuses as $index => $status) {
            $checks[] = $this->check('check_' . $index, $status);
        }

        $counts = HealthCounts::fromChecks($checks);

        $this->assertSame($overall, $counts->overall());
        $this->assertSame($summary, $counts->summary());
        $this->assertSame(count($statuses), $counts->total);
    }

    /**
     * @return array<string, array{0: list<HealthStatus>, 1: HealthStatus, 2: string}>
     */
    public static function aggregation(): array
    {
        return [
            'critical beats warning' => [
                [HealthStatus::Warning, HealthStatus::Critical, HealthStatus::Healthy],
                HealthStatus::Critical,
                'A health check reported a confirmed failure.',
            ],
            'warning beats unknown' => [
                [HealthStatus::Unknown, HealthStatus::Warning, HealthStatus::Skipped],
                HealthStatus::Warning,
                'A health check reported a degraded condition.',
            ],
            'unknown beats healthy' => [
                [HealthStatus::Healthy, HealthStatus::Unknown],
                HealthStatus::Unknown,
                'A health check could not determine health.',
            ],
            'skipped does not downgrade a pass' => [
                [HealthStatus::Healthy, HealthStatus::Skipped, HealthStatus::Skipped],
                HealthStatus::Healthy,
                'Every completed health check passed.',
            ],
            'all skipped' => [
                [HealthStatus::Skipped, HealthStatus::Skipped],
                HealthStatus::Skipped,
                'Every health check was skipped.',
            ],
            'empty' => [
                [],
                HealthStatus::Unknown,
                'No health checks were registered.',
            ],
        ];
    }

    #[Test]
    public function skipped_checks_stay_visible_when_the_report_is_healthy(): void
    {
        $counts = HealthCounts::fromChecks([
            $this->check('application', HealthStatus::Healthy),
            $this->check('database', HealthStatus::Skipped),
        ]);

        $this->assertSame(HealthStatus::Healthy, $counts->overall());
        $this->assertSame(1, $counts->healthy);
        $this->assertSame(1, $counts->skipped);
        $this->assertSame(0, $counts->unknown);
    }

    private function check(string $name, HealthStatus $status, ?string $remediation = null): HealthCheckResult
    {
        return new HealthCheckResult(
            $name,
            'A health check.',
            $status,
            'The check finished.',
            null,
            $status === HealthStatus::Healthy ? null : ($remediation ?? 'Run the check again.'),
            [new FindingEvidence('application.maintenance', Observation::Disabled)],
        );
    }

    /**
     * @param list<HealthCheckResult> $checks
     */
    private function report(array $checks): HealthInspection
    {
        $counts = HealthCounts::fromChecks($checks);

        return new HealthInspection($counts->overall(), $counts->summary(), self::ASSESSED_AT, $counts, $checks);
    }
}
