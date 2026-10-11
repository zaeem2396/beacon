<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Mcp;

use Beacon\Mcp\ServerRegistration;
use Illuminate\Routing\Route;
use Laravel\Mcp\Server\Registrar;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('mcp')]
final class ServerRegistrationTest extends McpTestCase
{
    #[Test]
    #[DefineEnvironment('enableMcp')]
    public function it_registers_a_local_server_when_explicitly_enabled(): void
    {
        $this->assertTrue($this->application()->make(ServerRegistration::class)->registered());
        $this->assertIsCallable(
            $this->application()->make(Registrar::class)->getLocalServer(ServerRegistration::HANDLE),
        );
    }

    #[Test]
    #[DefineEnvironment('enableMcp')]
    public function it_never_registers_an_http_endpoint(): void
    {
        $servers = $this->application()->make(Registrar::class)->servers();

        $this->assertSame([ServerRegistration::HANDLE], array_keys($servers));
        $this->assertNotInstanceOf(Route::class, $servers[ServerRegistration::HANDLE]);

        foreach ($this->application()->make('router')->getRoutes()->getRoutes() as $route) {
            $this->assertStringNotContainsString('beacon', $route->uri());
            $this->assertStringNotContainsString('mcp', $route->uri());
        }
    }

    #[Test]
    #[DefineEnvironment('enableMcp')]
    #[DefineEnvironment('useProduction')]
    public function it_is_not_registered_in_production_unless_allowed(): void
    {
        $registration = $this->application()->make(ServerRegistration::class);

        $this->assertFalse($registration->registered());
        $this->assertStringContainsString('[production]', $registration->decide()->reason);
    }

    #[Test]
    #[DefineEnvironment('enableMcpWithoutToken')]
    public function it_is_not_registered_without_a_token(): void
    {
        $this->assertFalse($this->application()->make(ServerRegistration::class)->registered());
    }

    #[Test]
    #[DefineEnvironment('enableMcp')]
    public function registering_twice_is_idempotent(): void
    {
        $registration = $this->application()->make(ServerRegistration::class);

        $this->assertTrue($registration->register());
        $this->assertCount(1, $this->application()->make(Registrar::class)->servers());
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function useProduction($app): void
    {
        $app['env'] = 'production';
        $app['config']->set('beacon.mcp.allowed_environments', ['local']);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableMcpWithoutToken($app): void
    {
        $this->enableMcp($app);
        $app['config']->set('beacon.auth.token', null);
    }
}
