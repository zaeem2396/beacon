<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Console;

use Beacon\Core\Version;
use Beacon\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

final class BeaconCommandTest extends TestCase
{
    #[Test]
    public function it_registers_the_beacon_commands(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('beacon', $commands);
        $this->assertArrayHasKey('beacon:application', $commands);
        $this->assertArrayHasKey('beacon:install', $commands);
        $this->assertArrayHasKey('beacon:check', $commands);
        $this->assertArrayHasKey('beacon:health', $commands);
        $this->assertArrayHasKey('beacon:database', $commands);
    }

    #[Test]
    public function it_shows_the_version_status_and_commands(): void
    {
        $this->assertSame(0, Artisan::call('beacon'));
        $output = Artisan::output();

        $this->assertStringContainsString('Laravel Beacon ' . Version::current(), $output);
        $this->assertStringContainsString('unavailable', $output);
        $this->assertStringContainsString('beacon:application', $output);
        $this->assertStringContainsString('beacon:install', $output);
        $this->assertStringContainsString('beacon:check', $output);
        $this->assertStringContainsString('beacon:health', $output);
        $this->assertStringContainsString('beacon:database', $output);
    }

    #[Test]
    public function the_version_comes_from_composer_metadata(): void
    {
        $this->assertNotSame('', Version::current());
        $this->assertNotSame('0.1.0-dev', Version::current());
    }

    #[Test]
    public function it_does_not_show_credentials(): void
    {
        $this->application()['config']->set('beacon.auth.token', 'super-secret-token-value-0123456789abcdef');

        Artisan::call('beacon');

        $this->assertStringNotContainsString('super-secret-token-value', Artisan::output());
    }

    #[Test]
    public function every_command_provides_help(): void
    {
        foreach (['beacon', 'beacon:application', 'beacon:install', 'beacon:check', 'beacon:health', 'beacon:database'] as $command) {
            $this->assertSame(0, Artisan::call('help', ['command_name' => $command]));
            $this->assertStringContainsString('Help:', Artisan::output(), $command);
        }

        Artisan::call('help', ['command_name' => 'beacon:check']);
        $help = Artisan::output();

        $this->assertStringContainsString('--json', $help);
        $this->assertStringContainsString('Exit codes', $help);
    }
}
