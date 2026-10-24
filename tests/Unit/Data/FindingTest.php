<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Data;

use Beacon\Data\Finding;
use Beacon\Data\FindingEvidence;
use Beacon\Data\FindingStatus;
use Beacon\Data\Observation;
use Beacon\Data\Severity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FindingTest extends TestCase
{
    #[Test]
    public function it_serializes_in_a_stable_order(): void
    {
        $this->assertSame([
            'id' => 'debug_enabled_in_production',
            'status' => 'fail',
            'severity' => 'high',
            'title' => 'Debug mode in production',
            'description' => 'Debug mode is enabled in production.',
            'evidence' => [
                ['name' => 'environment', 'observation' => 'production'],
                ['name' => 'app.debug', 'observation' => 'enabled'],
            ],
            'remediation' => 'Set APP_DEBUG to false in production.',
        ], $this->finding(FindingStatus::Fail, Severity::High, 'Set APP_DEBUG to false in production.')->toArray());
    }

    #[Test]
    public function a_pass_serializes_a_null_remediation_and_no_severity(): void
    {
        $data = $this->finding(FindingStatus::Pass, Severity::None, null)->toArray();

        $this->assertSame('pass', $data['status']);
        $this->assertSame('none', $data['severity']);
        $this->assertArrayHasKey('remediation', $data);
        $this->assertNull($data['remediation']);
    }

    #[Test]
    public function pass_and_unknown_cannot_carry_a_severity(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->finding(FindingStatus::Unknown, Severity::High, 'Set app.debug to a boolean.');
    }

    #[Test]
    public function a_failure_must_include_remediation_and_a_severity(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->finding(FindingStatus::Fail, Severity::None, null);
    }

    private function finding(FindingStatus $status, Severity $severity, ?string $remediation): Finding
    {
        return new Finding(
            'debug_enabled_in_production',
            $status,
            $severity,
            'Debug mode in production',
            'Debug mode is enabled in production.',
            [
                new FindingEvidence('environment', Observation::Production),
                new FindingEvidence('app.debug', Observation::Enabled),
            ],
            $remediation,
        );
    }
}
