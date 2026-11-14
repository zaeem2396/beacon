<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Core\Beacon;
use Beacon\Data\ExceptionInspection;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Inspectors\ExceptionInspector;
use Beacon\Security\Principal;
use Beacon\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ExceptionInspectorTest extends TestCase
{
    private string $logPath;

    #[Test]
    public function it_reads_exceptions_and_redacts_secrets(): void
    {
        $secret = 'sk_live_51BeaconExceptionSecretValue';
        file_put_contents(
            $this->logPath,
            '[2026-11-14 12:00:00] local.ERROR: RuntimeException: payment failed token=' . $secret . " for ops@example.com\n",
        );
        config()->set('beacon.security.allowed_capabilities', ['exceptions']);

        $inspection = $this->application()->make(ExceptionInspector::class)->inspect();
        $this->assertInstanceOf(ExceptionInspection::class, $inspection);
        $encoded = (string) json_encode(
            $this->application()->make(Beacon::class)->inspect(Principal::local(), ExceptionInspector::NAME)->toArray(),
        );

        $this->assertGreaterThan(0, $inspection->returned);
        $this->assertStringNotContainsString($secret, $encoded);
        $this->assertStringNotContainsString('ops@example.com', $encoded);
        $this->assertStringContainsString('redacted', $encoded);
    }

    #[Test]
    public function unauthorized_callers_do_not_read_logs(): void
    {
        file_put_contents($this->logPath, "[2026-11-14 12:00:00] local.ERROR: RuntimeException: should-not-be-read\n");
        config()->set('beacon.security.allowed_capabilities', ['runtime']);

        $this->expectException(AuthorizationException::class);
        $this->application()->make(Beacon::class)->inspect(Principal::local(), ExceptionInspector::NAME);
    }

    #[Test]
    public function later_pages_return_later_exceptions(): void
    {
        $lines = [];

        for ($i = 1; $i <= 5; $i++) {
            $minute = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $lines[] = "[2026-11-14 12:{$minute}:00] local.ERROR: RuntimeException: page-case-{$i}";
        }

        file_put_contents($this->logPath, implode("\n", $lines) . "\n");
        config()->set('beacon.security.allowed_capabilities', ['exceptions']);

        $pageOne = $this->application()->make(ExceptionInspector::class)->inspect([
            'limit' => 2,
            'page' => 1,
        ]);
        $pageTwo = $this->application()->make(ExceptionInspector::class)->inspect([
            'limit' => 2,
            'page' => 2,
        ]);

        $this->assertInstanceOf(ExceptionInspection::class, $pageOne);
        $this->assertInstanceOf(ExceptionInspection::class, $pageTwo);
        $this->assertSame(2, $pageOne->returned);
        $this->assertSame(2, $pageTwo->returned);
        $this->assertNotSame($pageOne->exceptions[0]->exceptionId, $pageTwo->exceptions[0]->exceptionId);
        $this->assertStringContainsString('page-case', $pageTwo->exceptions[0]->message);
    }

    #[Test]
    public function plain_error_lines_are_not_invented_as_runtime_exceptions(): void
    {
        file_put_contents(
            $this->logPath,
            "[2026-11-14 12:00:00] local.ERROR: payment failed without a throwable class\n",
        );
        config()->set('beacon.security.allowed_capabilities', ['exceptions']);

        $inspection = $this->application()->make(ExceptionInspector::class)->inspect();
        $this->assertInstanceOf(ExceptionInspection::class, $inspection);
        $this->assertSame(0, $inspection->returned);
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
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }

        parent::tearDown();
    }
}
