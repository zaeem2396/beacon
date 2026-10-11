<?php

declare(strict_types=1);

namespace Beacon\Mcp;

use Beacon\Core\BeaconConfig;
use Beacon\Mcp\Http\AuthenticateMcpRequest;
use Beacon\Security\AuthorizationDecision;
use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Router;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Registrar;

/**
 * Decides whether the Beacon MCP server may exist and registers it with
 * laravel/mcp when it may.
 *
 * The local (stdio) transport is registered whenever MCP access is allowed.
 * The HTTP transport is an additional opt-in and every route it creates,
 * including the 405 responses for GET and DELETE, sits behind bearer-token
 * authentication.
 */
final class ServerRegistration
{
    public const HANDLE = 'beacon';

    private bool $registered = false;

    private bool $httpRegistered = false;

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

    public function decideHttp(): AuthorizationDecision
    {
        $decision = $this->decide();

        if (! $decision->allowed) {
            return $decision;
        }

        if (! $this->config->mcpHttpEnabled()) {
            return AuthorizationDecision::deny('The Beacon MCP HTTP transport is disabled. Set BEACON_MCP_HTTP_ENABLED=true to enable it.');
        }

        return $decision;
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

        $registrar = $this->container->make(Registrar::class);
        $registrar->local(self::HANDLE, BeaconServer::class);

        if ($this->decideHttp()->allowed) {
            $this->container->make(Router::class)
                ->middleware(AuthenticateMcpRequest::class)
                ->group(fn () => $registrar->web($this->config->mcpHttpPath(), BeaconServer::class));

            $this->httpRegistered = true;
        }

        return $this->registered = true;
    }

    public function registered(): bool
    {
        return $this->registered;
    }

    public function httpRegistered(): bool
    {
        return $this->httpRegistered;
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
