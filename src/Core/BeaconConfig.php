<?php

declare(strict_types=1);

namespace Beacon\Core;

use Beacon\Security\AuthorizationDecision;
use Illuminate\Contracts\Config\Repository;

/**
 * Typed, validated access to the "beacon" configuration.
 *
 * Invalid or missing security-related values fall back to the secure default.
 */
final class BeaconConfig
{
    public const MINIMUM_TOKEN_LENGTH = 32;

    public function __construct(
        private readonly Repository $config,
        private readonly string $environment,
    ) {
    }

    public function enabled(): bool
    {
        return $this->bool('beacon.enabled', true);
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function mcpEnabled(): bool
    {
        return $this->bool('beacon.mcp.enabled', false);
    }

    /**
     * @return list<string>
     */
    public function mcpAllowedEnvironments(): array
    {
        return $this->stringList('beacon.mcp.allowed_environments');
    }

    public function authToken(): ?string
    {
        $token = $this->config->get('beacon.auth.token');

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    /**
     * Whether the MCP interface may be exposed in the current environment.
     *
     * MCP is only reachable when Beacon and MCP are explicitly enabled, a
     * sufficiently long token is configured and the environment is allowed.
     */
    public function mcpAccess(): AuthorizationDecision
    {
        if (! $this->enabled()) {
            return AuthorizationDecision::deny('Beacon is disabled.');
        }

        if (! $this->mcpEnabled()) {
            return AuthorizationDecision::deny('Beacon MCP is disabled. Set BEACON_MCP_ENABLED=true to enable it.');
        }

        $token = $this->authToken();

        if ($token === null) {
            return AuthorizationDecision::deny('No MCP authentication token is configured. Set BEACON_MCP_TOKEN.');
        }

        if (strlen($token) < self::MINIMUM_TOKEN_LENGTH) {
            return AuthorizationDecision::deny(sprintf(
                'The MCP authentication token must be at least %d characters long.',
                self::MINIMUM_TOKEN_LENGTH,
            ));
        }

        if (! in_array($this->environment, $this->mcpAllowedEnvironments(), true)) {
            return AuthorizationDecision::deny(sprintf(
                'Beacon MCP is not allowed in the [%s] environment. Add it to BEACON_MCP_ENVIRONMENTS to allow it.',
                $this->environment,
            ));
        }

        return AuthorizationDecision::allow();
    }

    /**
     * @return list<string>
     */
    public function allowedCapabilities(): array
    {
        return $this->stringList('beacon.security.allowed_capabilities');
    }

    /**
     * @return list<string>
     */
    public function additionalRedactedKeys(): array
    {
        return $this->stringList('beacon.redaction.additional_keys');
    }

    public function inspectorEnabled(string $name): bool
    {
        return $this->bool("beacon.inspectors.{$name}.enabled", true);
    }

    /**
     * @return array<string, mixed>
     */
    public function inspectorOptions(string $name): array
    {
        $options = $this->config->get("beacon.inspectors.{$name}", []);

        if (! is_array($options)) {
            return [];
        }

        $result = [];

        foreach ($options as $key => $value) {
            if (is_string($key) && $key !== 'enabled') {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function bool(string $key, bool $default): bool
    {
        $value = $this->config->get($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * @return list<string>
     */
    private function stringList(string $key): array
    {
        $value = $this->config->get($key, []);

        $items = match (true) {
            is_string($value) => explode(',', $value),
            is_array($value) => $value,
            default => [],
        };

        $result = [];

        foreach ($items as $item) {
            if (is_string($item) && trim($item) !== '') {
                $result[] = trim($item);
            }
        }

        return $result;
    }
}
