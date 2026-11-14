<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Console;

use Beacon\Console\Commands\RuntimeCommand;
use Beacon\Inspectors\RuntimeInspector;
use Beacon\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

final class RuntimeCommandTest extends TestCase
{
    #[Test]
    public function it_is_denied_until_the_runtime_capability_is_allowed(): void
    {
        $exitCode = Artisan::call('beacon:runtime');
        $output = Artisan::output();

        $this->assertSame(RuntimeCommand::EXIT_DENIED, $exitCode);
        $this->assertStringContainsString('not allowed', $output);
        $this->assertStringContainsString('BEACON_ALLOWED_CAPABILITIES=runtime', $output);
    }

    #[Test]
    public function json_output_matches_the_inspector(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['runtime']);

        $exitCode = Artisan::call('beacon:runtime', ['--json' => true]);
        $output = Artisan::output();
        $decoded = json_decode($output, true);
        $expected = $this->application()->make(RuntimeInspector::class)->inspect()->toArray();

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame($expected, $decoded);
        $this->assertSame(PHP_VERSION, data_get($decoded, 'php.version'));
        $this->assertDoesNotMatchRegularExpression('/\e/', $output);
    }
}
