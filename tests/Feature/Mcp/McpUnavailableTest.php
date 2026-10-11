<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Mcp;

use Beacon\Core\BeaconConfig;
use Beacon\Mcp\McpUnavailableException;
use Beacon\Mcp\ServerRegistration;
use Beacon\Tests\TestCase;
use Laravel\Mcp\Server;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;

/**
 * Runs without the laravel/mcp service provider, and in CI without the package installed.
 */
final class McpUnavailableTest extends TestCase
{
    #[Test]
    #[DefineEnvironment('enableMcp')]
    public function beacon_boots_without_laravel_mcp(): void
    {
        $registration = $this->application()->make(ServerRegistration::class);

        $this->assertFalse($registration->registered());
        $this->assertStringContainsString(
            class_exists(Server::class) ? 'service provider is not registered' : 'composer require laravel/mcp',
            $registration->decide()->reason,
        );
    }

    #[Test]
    #[DefineEnvironment('enableMcp')]
    public function it_reports_a_missing_laravel_mcp_package(): void
    {
        $registration = new ServerRegistration(
            $this->application(),
            $this->application()->make(BeaconConfig::class),
            sdkInstalled: false,
        );

        $this->assertFalse($registration->register());
        $this->assertStringContainsString('composer require laravel/mcp', $registration->decide()->reason);

        $this->expectException(McpUnavailableException::class);

        $registration->ensureAvailable();
    }

    #[Test]
    public function a_disabled_server_reports_that_it_is_disabled_before_checking_the_package(): void
    {
        $registration = new ServerRegistration(
            $this->application(),
            $this->application()->make(BeaconConfig::class),
            sdkInstalled: false,
        );

        $this->assertStringContainsString('disabled', $registration->decide()->reason);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableMcp($app): void
    {
        $app['config']->set('beacon.mcp.enabled', true);
        $app['config']->set('beacon.mcp.allowed_environments', ['testing']);
        $app['config']->set('beacon.auth.token', McpTestCase::TOKEN);
    }
}
