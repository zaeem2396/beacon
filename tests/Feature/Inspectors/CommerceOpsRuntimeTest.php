<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Data\ErrorAggregateInspection;
use Beacon\Data\ExceptionInspection;
use Beacon\Data\RuntimeInspection;
use Beacon\Data\SchedulerInspection;
use Beacon\Inspectors\ErrorAggregateInspector;
use Beacon\Inspectors\ExceptionInspector;
use Beacon\Inspectors\RuntimeInspector;
use Beacon\Inspectors\SchedulerInspector;
use Beacon\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Optional runtime and log checks against a CommerceOps checkout.
 *
 * Runs only when BEACON_COMMERCEOPS_HOST and BEACON_COMMERCEOPS_PATH are set.
 * Controlled log lines are appended and removed. Application data is never
 * mutated permanently.
 */
#[Group('commerceops')]
final class CommerceOpsRuntimeTest extends TestCase
{
    #[Test]
    public function commerceops_runtime_logs_and_scheduler_can_be_inspected(): void
    {
        $host = $this->env('BEACON_COMMERCEOPS_HOST');
        $root = $this->env('BEACON_COMMERCEOPS_PATH');

        if ($host === null || $root === null || ! is_dir($root)) {
            $this->markTestSkipped('CommerceOps is not available for runtime inspection.');
        }

        $previous = $this->application()->basePath();
        $logDirectory = $root . '/storage/logs';
        $logPath = $logDirectory . '/beacon-runtime-probe.log';
        $marker = 'beacon-commerceops-runtime-probe-' . bin2hex(random_bytes(4));
        $secret = 'sk_live_51BeaconCommerceOpsProbeSecret';

        try {
            $this->application()->setBasePath($root);

            if (! is_dir($logDirectory)) {
                mkdir($logDirectory, 0o777, true);
            }

            file_put_contents(
                $logPath,
                '[2026-11-14 15:00:00] local.ERROR: RuntimeException: ' . $marker . ' token=' . $secret . "\n"
                . '[2026-11-14 15:01:00] local.ERROR: RuntimeException: ' . $marker . " again\n",
            );

            config()->set('logging.default', 'single');
            config()->set('logging.channels.single', [
                'driver' => 'single',
                'path' => $logPath,
                'level' => 'debug',
            ]);
            config()->set('beacon.security.allowed_capabilities', [
                'runtime',
                'exceptions',
                'error_aggregate',
                'scheduler',
            ]);

            $runtime = $this->application()->make(RuntimeInspector::class)->inspect();
            $this->assertInstanceOf(RuntimeInspection::class, $runtime);
            $this->assertNotSame('', $runtime->laravel->version);

            $exceptions = $this->application()->make(ExceptionInspector::class)->inspect(['limit' => 25]);
            $this->assertInstanceOf(ExceptionInspection::class, $exceptions);
            $encoded = (string) json_encode($exceptions->toArray());
            $this->assertStringContainsString($marker, $encoded);
            $this->assertStringNotContainsString($secret, $encoded);

            $aggregates = $this->application()->make(ErrorAggregateInspector::class)->inspect(['limit' => 25]);
            $this->assertInstanceOf(ErrorAggregateInspection::class, $aggregates);
            $this->assertGreaterThan(0, $aggregates->summary->matchedRecords);

            $this->application()->make(Schedule::class)->command('inspire')->daily();
            $scheduler = $this->application()->make(SchedulerInspector::class)->inspect();
            $this->assertInstanceOf(SchedulerInspection::class, $scheduler);
            $this->assertGreaterThan(0, $scheduler->returned);
        } finally {
            if (is_file($logPath)) {
                unlink($logPath);
            }

            $this->application()->setBasePath($previous);
            config()->set('database.default', 'sqlite');
        }
    }

    private function env(string $name): ?string
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            return null;
        }

        return $value;
    }
}
