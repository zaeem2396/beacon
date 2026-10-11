<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Mcp;

use Beacon\BeaconServiceProvider;
use Beacon\Tests\TestCase;
use Laravel\Mcp\Server\McpServiceProvider;

abstract class McpTestCase extends TestCase
{
    public const TOKEN = '0123456789abcdef0123456789abcdef';

    protected function getPackageProviders($app): array
    {
        return [McpServiceProvider::class, BeaconServiceProvider::class];
    }

    /**
     * Configuration that satisfies every MCP access condition in the testing environment.
     *
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableMcp($app): void
    {
        $app['config']->set('beacon.mcp.enabled', true);
        $app['config']->set('beacon.mcp.allowed_environments', ['testing']);
        $app['config']->set('beacon.auth.token', self::TOKEN);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableHttp($app): void
    {
        $this->enableMcp($app);
        $app['config']->set('beacon.mcp.http.enabled', true);
    }
}
