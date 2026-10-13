<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Diagnostics;

use Beacon\Diagnostics\CheckResult;
use Beacon\Diagnostics\DiagnosticReport;
use Beacon\Diagnostics\DiagnosticStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DiagnosticReportTest extends TestCase
{
    /**
     * @return iterable<string, array{list<DiagnosticStatus>, string, int}>
     */
    public static function outcomes(): iterable
    {
        yield 'no checks' => [[], 'ok', 0];
        yield 'informational statuses' => [[DiagnosticStatus::Pass, DiagnosticStatus::Disabled, DiagnosticStatus::NotImplemented], 'ok', 0];
        yield 'warning' => [[DiagnosticStatus::Pass, DiagnosticStatus::Warn], 'warn', 0];
        yield 'misconfigured beats warning' => [[DiagnosticStatus::Warn, DiagnosticStatus::Misconfigured], 'misconfigured', 1];
        yield 'failure beats everything' => [[DiagnosticStatus::Misconfigured, DiagnosticStatus::Fail, DiagnosticStatus::Warn], 'fail', 2];
    }

    /**
     * @param list<DiagnosticStatus> $statuses
     */
    #[Test]
    #[DataProvider('outcomes')]
    public function it_reports_the_most_severe_status(array $statuses, string $status, int $exitCode): void
    {
        $report = new DiagnosticReport(array_map(
            static fn (DiagnosticStatus $status): CheckResult => new CheckResult($status->value, $status->value, $status, 'message'),
            $statuses,
        ));

        $this->assertSame($status, $report->status());
        $this->assertSame($exitCode, $report->exitCode());
        $this->assertSame($exitCode, $report->toArray()['exit_code']);
    }
}
