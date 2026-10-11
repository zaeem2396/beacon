<?php

declare(strict_types=1);

namespace Beacon\Mcp;

use Illuminate\Support\ServiceProvider;

/**
 * Wires Beacon into laravel/mcp. Safe to load when laravel/mcp is not installed:
 * no laravel/mcp class is touched unless MCP is enabled and the package exists.
 */
final class BeaconMcpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(McpRegistry::class);
        $this->app->singleton(ServerRegistration::class);
    }

    public function boot(): void
    {
        $this->app->make(ServerRegistration::class)->register();
    }
}
