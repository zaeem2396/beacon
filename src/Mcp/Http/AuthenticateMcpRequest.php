<?php

declare(strict_types=1);

namespace Beacon\Mcp\Http;

use Beacon\Contracts\Authenticator;
use Beacon\Core\BeaconConfig;
use Beacon\Mcp\ServerRegistration;
use Beacon\Security\AuthenticationFailure;
use Beacon\Security\Principal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards every request to the Beacon MCP HTTP endpoint.
 *
 * Access is re-checked per request so that a cached route cannot outlive the
 * configuration that allowed it. Responses never describe why credentials
 * were rejected beyond the RFC 6750 error code.
 */
final class AuthenticateMcpRequest
{
    public function __construct(
        private readonly ServerRegistration $registration,
        private readonly BeaconConfig $config,
        private readonly Authenticator $authenticator,
    ) {
    }

    /**
     * @param \Closure(Request): Response $next
     */
    public function handle(Request $request, \Closure $next): Response
    {
        if (! $this->registration->decideHttp()->allowed) {
            return $this->notFound();
        }

        if ($this->config->mcpHttpRequiresHttps() && ! $request->secure()) {
            return $this->respond(403, 'HTTPS is required.');
        }

        $result = $this->authenticator->authenticate($request);

        if (! $result->authenticated()) {
            return match ($result->failure) {
                AuthenticationFailure::NotConfigured => $this->notFound(),
                AuthenticationFailure::Missing => $this->respond(401, 'Unauthenticated.', 'Bearer realm="mcp"'),
                AuthenticationFailure::Invalid => $this->respond(401, 'Unauthenticated.', 'Bearer realm="mcp", error="invalid_token"'),
                AuthenticationFailure::Malformed => $this->respond(400, 'Bad request.', 'Bearer realm="mcp", error="invalid_request"'),
            };
        }

        $request->attributes->set(Principal::class, $result->principal);

        return $next($request);
    }

    private function notFound(): JsonResponse
    {
        return $this->respond(404, 'Not found.');
    }

    private function respond(int $status, string $message, ?string $challenge = null): JsonResponse
    {
        $response = new JsonResponse(['message' => $message], $status, ['Cache-Control' => 'no-store']);

        if ($challenge !== null) {
            $response->headers->set('WWW-Authenticate', $challenge);
        }

        return $response;
    }
}
