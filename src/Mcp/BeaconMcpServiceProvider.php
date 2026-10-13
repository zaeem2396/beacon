<?php

declare(strict_types=1);

namespace Beacon\Mcp;

use Beacon\Mcp\Tools\GetApplicationInfoTool;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Server\Tool;

/**
 * Wires Beacon into laravel/mcp. Safe to load when laravel/mcp is not installed:
 * Beacon's tools are only registered when the package exists, and the server
 * only when MCP is also enabled.
 */
final class BeaconMcpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(McpRegistry::class);
        $this->app->singleton(ServerRegistration::class);

        $this->callAfterResolving(McpRegistry::class, static function (McpRegistry $registry): void {
            // Beacon's tools extend laravel/mcp classes and cannot be loaded without it.
            if (class_exists(Tool::class)) {
                $registry->tool(GetApplicationInfoTool::class);
            }
        });
    }

    public function boot(): void
    {
        $this->app->make(ServerRegistration::class)->register();
    }
}
