<?php

declare(strict_types=1);

namespace Beacon\Mcp;

use Beacon\Security\Principal;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Transport\HttpTransport;
use Laravel\Mcp\Server\Transport\StdioTransport;

/**
 * Determines who is calling the Beacon MCP server.
 *
 * Only identities Beacon established itself are trusted: the local process
 * for stdio, and the principal set by {@see Http\AuthenticateMcpRequest} for
 * HTTP. Client-supplied headers or metadata are never used. Any other
 * transport has no identity, so every protected operation is denied.
 */
final class CallerResolver
{
    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function resolve(Transport $transport): ?Principal
    {
        if ($transport instanceof StdioTransport) {
            return Principal::local();
        }

        if ($transport instanceof HttpTransport && $this->container->bound('request')) {
            $request = $this->container->make('request');
            $principal = $request instanceof Request ? $request->attributes->get(Principal::class) : null;

            return $principal instanceof Principal ? $principal : null;
        }

        return null;
    }
}
