<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Core\Beacon;
use Beacon\Data\ErrorAggregateInspection;
use Beacon\Data\ErrorSummaryInspection;
use Beacon\Inspectors\ErrorAggregateInspector;
use Beacon\Inspectors\ErrorSummaryInspector;
use Beacon\Security\Principal;
use Beacon\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ErrorAggregateInspectorTest extends TestCase
{
    private string $logPath;

    #[Test]
    public function it_groups_equivalent_exceptions(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['error_aggregate']);

        $inspection = $this->application()->make(ErrorAggregateInspector::class)->inspect();
        $this->assertInstanceOf(ErrorAggregateInspection::class, $inspection);
        $this->assertGreaterThanOrEqual(2, $inspection->summary->uniqueGroups);

        $runtime = null;

        foreach ($inspection->groups as $group) {
            if ($group->className === 'RuntimeException') {
                $runtime = $group;
            }
        }

        $this->assertNotNull($runtime);
        $this->assertSame(2, $runtime->occurrences);

        $payload = $this->application()->make(Beacon::class)->inspect(Principal::local(), ErrorAggregateInspector::NAME)->toArray();
        $this->assertIsArray($payload['groups'] ?? null);
    }

    #[Test]
    public function error_summary_does_not_require_error_aggregate_capability(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['error_summary']);

        $inspection = $this->application()->make(ErrorSummaryInspector::class)->inspect();
        $this->assertInstanceOf(ErrorSummaryInspection::class, $inspection);
        $this->assertGreaterThan(0, $inspection->summary->matchedRecords);

        $payload = $this->application()->make(Beacon::class)->inspect(Principal::local(), ErrorSummaryInspector::NAME)->toArray();
        $this->assertIsArray($payload['summary'] ?? null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $directory = $this->application()->storagePath('logs');

        if (! is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        $this->logPath = $directory . '/laravel.log';
        config()->set('logging.default', 'single');
        config()->set('logging.channels.single', [
            'driver' => 'single',
            'path' => $this->logPath,
            'level' => 'debug',
        ]);
        file_put_contents($this->logPath, implode("\n", [
            '[2026-11-14 12:00:00] local.ERROR: RuntimeException: Order 12345 failed',
            '[2026-11-14 12:01:00] local.ERROR: RuntimeException: Order 99999 failed',
            '[2026-11-14 12:02:00] local.ERROR: LogicException: Different failure',
        ]) . "\n");
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }

        parent::tearDown();
    }
}
