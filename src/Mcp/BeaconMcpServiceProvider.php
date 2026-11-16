<?php

declare(strict_types=1);

namespace Beacon\Mcp;

use Beacon\Mcp\Tools\AnalyzeMigrationRisksTool;
use Beacon\Mcp\Tools\CompareSchemaSnapshotsTool;
use Beacon\Mcp\Tools\CreateSchemaSnapshotTool;
use Beacon\Mcp\Tools\GetApplicationInfoTool;
use Beacon\Mcp\Tools\GetConfigTool;
use Beacon\Mcp\Tools\GetDatabaseSchemaTool;
use Beacon\Mcp\Tools\GetDatabaseStatusTool;
use Beacon\Mcp\Tools\GetEnvironmentTool;
use Beacon\Mcp\Tools\GetErrorAggregatesTool;
use Beacon\Mcp\Tools\GetErrorSummaryTool;
use Beacon\Mcp\Tools\GetFailedJobsTool;
use Beacon\Mcp\Tools\GetHealthTool;
use Beacon\Mcp\Tools\GetInvestigationContextTool;
use Beacon\Mcp\Tools\GetLogSummaryTool;
use Beacon\Mcp\Tools\GetMigrationsTool;
use Beacon\Mcp\Tools\GetQueueStatusTool;
use Beacon\Mcp\Tools\GetRecentExceptionsTool;
use Beacon\Mcp\Tools\GetRecentRequestsTool;
use Beacon\Mcp\Tools\GetRequestSummaryTool;
use Beacon\Mcp\Tools\GetRoutesTool;
use Beacon\Mcp\Tools\GetRuntimeInfoTool;
use Beacon\Mcp\Tools\GetSchedulerStatusTool;
use Beacon\Mcp\Tools\GetSlowRequestsTool;
use Beacon\Mcp\Tools\GetSlowRequestSummaryTool;
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
                $registry->tool(GetEnvironmentTool::class);
                $registry->tool(GetRoutesTool::class);
                $registry->tool(GetConfigTool::class);
                $registry->tool(GetHealthTool::class);
                $registry->tool(GetDatabaseStatusTool::class);
                $registry->tool(GetDatabaseSchemaTool::class);
                $registry->tool(GetMigrationsTool::class);
                $registry->tool(AnalyzeMigrationRisksTool::class);
                $registry->tool(CreateSchemaSnapshotTool::class);
                $registry->tool(CompareSchemaSnapshotsTool::class);
                $registry->tool(GetRuntimeInfoTool::class);
                $registry->tool(GetRecentExceptionsTool::class);
                $registry->tool(GetErrorAggregatesTool::class);
                $registry->tool(GetErrorSummaryTool::class);
                $registry->tool(GetLogSummaryTool::class);
                $registry->tool(GetQueueStatusTool::class);
                $registry->tool(GetFailedJobsTool::class);
                $registry->tool(GetSchedulerStatusTool::class);
                $registry->tool(GetRecentRequestsTool::class);
                $registry->tool(GetRequestSummaryTool::class);
                $registry->tool(GetSlowRequestsTool::class);
                $registry->tool(GetSlowRequestSummaryTool::class);
                $registry->tool(GetInvestigationContextTool::class);
            }
        });
    }

    public function boot(): void
    {
        $this->app->make(ServerRegistration::class)->register();
    }
}
