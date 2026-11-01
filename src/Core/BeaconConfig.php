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

    public const DEFAULT_HTTP_PATH = 'beacon/mcp';

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

    public function mcpHttpEnabled(): bool
    {
        return $this->bool('beacon.mcp.http.enabled', false);
    }

    /**
     * URI of the HTTP endpoint, without leading or trailing slashes.
     */
    public function mcpHttpPath(): string
    {
        $path = $this->config->get('beacon.mcp.http.path');
        $path = is_string($path) ? trim($path, " \t\n\r\0\x0B/") : '';

        return preg_match('#^[A-Za-z0-9._~-]+(/[A-Za-z0-9._~-]+)*$#', $path) === 1 ? $path : self::DEFAULT_HTTP_PATH;
    }

    public function mcpHttpRequiresHttps(): bool
    {
        return $this->bool('beacon.mcp.http.require_https', true);
    }

    public function authToken(): ?string
    {
        return $this->string('beacon.auth.token');
    }

    /**
     * Tokens an HTTP client may present: the current token and, during
     * rotation, the previous one. Tokens that are too short are never accepted.
     *
     * @return array<string, string> token keyed by a non-secret label
     */
    public function acceptedTokens(): array
    {
        $tokens = [
            'current' => $this->authToken(),
            'previous' => $this->string('beacon.auth.previous_token'),
        ];

        return array_filter(
            $tokens,
            static fn (?string $token): bool => $token !== null && strlen($token) >= self::MINIMUM_TOKEN_LENGTH,
        );
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

    public function healthDatabaseEnabled(): bool
    {
        return $this->bool('beacon.health.database.enabled', false);
    }

    /**
     * Seconds for the database probe, from 1 to 5. Null when the configured
     * value is present and not in that range. A missing value uses 2.
     */
    public function healthDatabaseTimeout(): ?int
    {
        return $this->boundedTimeout('beacon.health.database.timeout_seconds');
    }

    /**
     * Seconds for the database status probe, from 1 to 5. Null when the
     * configured value is present and not in that range. A missing value uses 2.
     */
    public function databaseTimeout(): ?int
    {
        return $this->boundedTimeout('beacon.database.timeout_seconds');
    }

    public function healthCacheEnabled(): bool
    {
        return $this->bool('beacon.health.cache.enabled', false);
    }

    public function healthQueueEnabled(): bool
    {
        return $this->bool('beacon.health.queue.enabled', false);
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

    private function boundedTimeout(string $key): ?int
    {
        $value = $this->config->get($key, 2);

        if (is_int($value) && $value >= 1 && $value <= 5) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-5]$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function string(string $key): ?string
    {
        $value = $this->config->get($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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
