<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Console;

use Beacon\Mcp\ServerRegistration;
use Beacon\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;

final class InstallCommandTest extends TestCase
{
    private string $path;

    #[Test]
    public function it_publishes_the_configuration(): void
    {
        $this->install()
            ->expectsOutputToContain('Published config/beacon.php')
            ->assertSuccessful()
            ->run();

        $this->assertFileExists($this->path);
        $this->assertFileEquals(__DIR__ . '/../../../config/beacon.php', $this->path);
    }

    #[Test]
    public function it_never_overwrites_an_existing_configuration(): void
    {
        Artisan::call('beacon:install');
        file_put_contents($this->path, "<?php\n\n// customized\nreturn [];\n");

        foreach ([1, 2] as $run) {
            $this->install()
                ->expectsOutputToContain('already exists and was left unchanged')
                ->assertSuccessful()
                ->run();

            $this->assertStringEqualsFile($this->path, "<?php\n\n// customized\nreturn [];\n", "Run {$run}");
        }
    }

    #[Test]
    public function it_explains_manual_configuration_without_generating_credentials(): void
    {
        Artisan::call('beacon:install');
        $output = Artisan::output();

        $this->assertStringContainsString('BEACON_MCP_ENABLED=true', $output);
        $this->assertStringContainsString('BEACON_MCP_TOKEN=<random token of at least 32 characters>', $output);
        $this->assertStringContainsString('beacon:check', $output);
        $this->assertDoesNotMatchRegularExpression('/[A-Za-z0-9+\/]{32,}/', $output);
    }

    #[Test]
    public function it_does_not_enable_mcp_or_remote_access(): void
    {
        $env = $this->application()->basePath('.env');
        $before = is_file($env) ? md5_file($env) : null;

        Artisan::call('beacon:install');

        $this->assertSame($before, is_file($env) ? md5_file($env) : null);
        $this->assertFalse((bool) config('beacon.mcp.enabled'));
        $this->assertFalse((bool) config('beacon.mcp.http.enabled'));
        $this->assertFalse($this->application()->make(ServerRegistration::class)->registered());
        $this->assertFalse($this->application()->make(ServerRegistration::class)->httpRegistered());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = $this->application()->configPath('beacon.php');
        @unlink($this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    private function install(): PendingCommand
    {
        $command = $this->artisan('beacon:install');
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }
}
