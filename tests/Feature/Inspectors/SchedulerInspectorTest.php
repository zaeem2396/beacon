<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Core\Beacon;
use Beacon\Data\SchedulerInspection;
use Beacon\Inspectors\SchedulerInspector;
use Beacon\Security\Principal;
use Beacon\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;

final class SchedulerInspectorTest extends TestCase
{
    #[Test]
    public function it_lists_registered_scheduled_tasks(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['scheduler']);
        $this->application()->make(Schedule::class)->command('inspire')->hourly();

        $inspection = $this->application()->make(SchedulerInspector::class)->inspect();
        $this->assertInstanceOf(SchedulerInspection::class, $inspection);
        $this->assertGreaterThan(0, $inspection->returned);
        $this->assertSame('Execution history was not available.', $inspection->tasks[0]->executionHistory);
        $this->assertStringContainsString('Scheduled task definitions were listed', $inspection->summary);

        $payload = $this->application()->make(Beacon::class)->inspect(Principal::local(), SchedulerInspector::NAME)->toArray();
        $this->assertGreaterThan(0, $payload['returned'] ?? 0);
    }

    #[Test]
    public function it_rejects_arguments(): void
    {
        $this->expectException(\Beacon\Exceptions\InvalidRuntimeQueryException::class);
        $this->application()->make(SchedulerInspector::class)->inspect(['force' => true]);
    }
}
