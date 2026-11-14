<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Logs;

use Beacon\Logs\LogLevel;
use Beacon\Logs\LogParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LogParserTest extends TestCase
{
    #[Test]
    public function it_parses_laravel_file_log_entries_with_traces(): void
    {
        $text = <<<'LOG'
            [2026-11-14 10:00:00] local.ERROR: RuntimeException: boom at /var/www/app/OrderService.php:42
            #0 /var/www/app/Worker.php(10): App\OrderService->run()
            #1 /var/www/app/artisan(35): include()
            [2026-11-14 10:01:00] local.INFO: Request finished
            LOG;

        $parsed = (new LogParser())->parse($text, 'single');

        $this->assertSame(0, $parsed['failures']);
        $this->assertCount(2, $parsed['records']);
        $this->assertSame(LogLevel::Error, $parsed['records'][0]->level);
        $this->assertSame('RuntimeException', $parsed['records'][0]->exceptionClass);
        $this->assertSame('OrderService.php', $parsed['records'][0]->file);
        $this->assertSame(42, $parsed['records'][0]->line);
        $this->assertTrue($parsed['records'][0]->tracePresent);
        $this->assertSame(LogLevel::Info, $parsed['records'][1]->level);
        $this->assertSame('OrderService.php', $parsed['records'][0]->file);
        $this->assertStringNotContainsString('/var/www', (string) $parsed['records'][0]->file);
    }

    #[Test]
    public function naive_timestamps_use_the_process_timezone_then_utc(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            $parsed = (new LogParser())->parse(
                "[2026-11-14 12:00:00] local.ERROR: RuntimeException: boom\n",
                'single',
            );

            $this->assertSame('2026-11-14T17:00:00+00:00', $parsed['records'][0]->timestamp);
        } finally {
            date_default_timezone_set($previous);
        }
    }
}
