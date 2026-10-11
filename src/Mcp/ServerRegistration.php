<?php

declare(strict_types=1);

namespace Beacon\Mcp;

use Beacon\Core\BeaconConfig;
use Beacon\Security\AuthorizationDecision;
use Illuminate\Contracts\Container\Container;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Registrar;

/**
 * Decides whether the Beacon MCP server may exist and registers it with
 * laravel/mcp when it may.
 *
 * Only the local (stdio) transport is registered. No HTTP endpoint is exposed
 * until MCP authentication is implemented.
 */
final class ServerRegistration
{
    public const HANDLE = 'beacon';

    private bool $registered = false;

    public function __construct(
        private readonly Container $container,
        private readonly BeaconConfig $config,
        private readonly ?bool $sdkInstalled = null,
    ) {
    }

    public function decide(): AuthorizationDecision
    {
        $access = $this->config->mcpAccess();

        if (! $access->allowed) {
            return $access;
        }

        if (! ($this->sdkInstalled ?? class_exists(Server::class))) {
            return AuthorizationDecision::deny('The laravel/mcp package is not installed. Run "composer require laravel/mcp".');
        }

        if (! $this->container->bound(Registrar::class)) {
            return AuthorizationDecision::deny('The laravel/mcp service provider is not registered.');
        }

        return $access;
    }

    /**
     * Register the server if allowed. Returns whether it is registered.
     */
    public function register(): bool
    {
        if ($this->registered) {
            return true;
        }

        if (! $this->decide()->allowed) {
            return false;
        }

        $this->container->make(Registrar::class)->local(self::HANDLE, BeaconServer::class);

        return $this->registered = true;
    }

    public function registered(): bool
    {
        return $this->registered;
    }

    /**
     * @throws McpUnavailableException
     */
    public function ensureAvailable(): void
    {
        $decision = $this->decide();

        if (! $decision->allowed) {
            throw McpUnavailableException::because($decision->reason);
        }
    }
}
