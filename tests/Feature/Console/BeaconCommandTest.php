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
        $this->assertArrayHasKey('beacon:schema', $commands);
        $this->assertArrayHasKey('beacon:migrations', $commands);
        $this->assertArrayHasKey('beacon:migration-risks', $commands);
        $this->assertArrayHasKey('beacon:schema-snapshot', $commands);
        $this->assertArrayHasKey('beacon:schema-compare', $commands);
        $this->assertArrayHasKey('beacon:runtime', $commands);
        $this->assertArrayHasKey('beacon:exceptions', $commands);
        $this->assertArrayHasKey('beacon:errors', $commands);
        $this->assertArrayHasKey('beacon:logs', $commands);
        $this->assertArrayHasKey('beacon:queues', $commands);
        $this->assertArrayHasKey('beacon:failed-jobs', $commands);
        $this->assertArrayHasKey('beacon:scheduler', $commands);
        $this->assertArrayHasKey('beacon:requests', $commands);
        $this->assertArrayHasKey('beacon:request-summary', $commands);
        $this->assertArrayHasKey('beacon:slow-requests', $commands);
        $this->assertArrayHasKey('beacon:slow-request-summary', $commands);
        $this->assertArrayHasKey('beacon:requests-prune', $commands);
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
        $this->assertStringContainsString('beacon:schema', $output);
        $this->assertStringContainsString('beacon:migrations', $output);
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
        foreach (['beacon', 'beacon:application', 'beacon:install', 'beacon:check', 'beacon:health', 'beacon:database', 'beacon:schema', 'beacon:migrations', 'beacon:migration-risks', 'beacon:schema-snapshot', 'beacon:schema-compare', 'beacon:runtime', 'beacon:exceptions', 'beacon:errors', 'beacon:logs', 'beacon:queues', 'beacon:failed-jobs', 'beacon:scheduler', 'beacon:requests', 'beacon:request-summary', 'beacon:slow-requests', 'beacon:slow-request-summary', 'beacon:requests-prune'] as $command) {
            $this->assertSame(0, Artisan::call('help', ['command_name' => $command]));
            $this->assertStringContainsString('Help:', Artisan::output(), $command);
        }

        Artisan::call('help', ['command_name' => 'beacon:check']);
        $help = Artisan::output();

        $this->assertStringContainsString('--json', $help);
        $this->assertStringContainsString('Exit codes', $help);
    }
}
