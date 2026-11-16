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

    /**
     * Seconds for schema inspection, from 1 to 5. Null when the configured
     * value is present and not in that range. A missing value uses 2.
     */
    public function schemaTimeout(): ?int
    {
        return $this->boundedTimeout('beacon.schema.timeout_seconds');
    }

    /**
     * Connection names that may be inspected in addition to the default.
     *
     * @return list<string>
     */
    public function schemaConnections(): array
    {
        return $this->stringList('beacon.schema.connections');
    }

    /**
     * Table or view names to omit. A trailing * is a prefix, not a regular expression.
     *
     * @return list<string>
     */
    public function schemaExclusions(): array
    {
        return $this->stringList('beacon.schema.exclude');
    }

    /**
     * Seconds for migration inspection, from 1 to 5. Null when the configured
     * value is present and not in that range. A missing value uses 2.
     */
    public function migrationsTimeout(): ?int
    {
        return $this->boundedTimeout('beacon.migrations.timeout_seconds');
    }

    /**
     * Connection names that may be inspected in addition to the default.
     *
     * @return list<string>
     */
    public function migrationsConnections(): array
    {
        return $this->stringList('beacon.migrations.connections');
    }

    /**
     * Seconds for the migration-risk repository read, from 1 to 5.
     * Null when the configured value is present and not in that range.
     */
    public function migrationRiskTimeout(): ?int
    {
        return $this->boundedTimeout('beacon.migration_risk.timeout_seconds');
    }

    /**
     * Connection names that may be analyzed in addition to the default.
     *
     * @return list<string>
     */
    public function migrationRiskConnections(): array
    {
        return $this->stringList('beacon.migration_risk.connections');
    }

    /**
     * Whether HTTP request collection middleware may write observations.
     * Disabled by default. Registration alone does not collect.
     */
    public function requestCollectionEnabled(): bool
    {
        return $this->bool('beacon.requests.collection.enabled', false);
    }

    /**
     * Whether Beacon appends request middleware to the web and api groups.
     * Default true. Collection still requires requestCollectionEnabled.
     */
    public function requestMiddlewareEnabled(): bool
    {
        return $this->bool('beacon.requests.middleware', true);
    }

    /**
     * Hours to retain request observations. Bounded from 1 to 720. Default 168.
     */
    public function requestRetentionHours(): int
    {
        $value = $this->config->get('beacon.requests.retention_hours', 168);

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return 168;
        }

        $hours = (int) $value;

        return $hours < 1 || $hours > 720 ? 168 : $hours;
    }

    /**
     * Default and endpoint-specific slow-request thresholds.
     */
    public function slowRequestThresholds(): \Beacon\Requests\SlowThresholds
    {
        $default = $this->config->get('beacon.requests.slow.threshold_ms', \Beacon\Requests\SlowThresholds::DEFAULT_MS);
        $defaultMs = is_int($default) || (is_string($default) && ctype_digit($default))
            ? (int) $default
            : \Beacon\Requests\SlowThresholds::DEFAULT_MS;

        if ($defaultMs < \Beacon\Requests\SlowThresholds::MIN_MS || $defaultMs > \Beacon\Requests\SlowThresholds::MAX_MS) {
            $defaultMs = \Beacon\Requests\SlowThresholds::DEFAULT_MS;
        }

        $raw = $this->config->get('beacon.requests.slow.endpoints', []);
        $endpoints = [];

        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                if (! is_string($key) || $key === '') {
                    continue;
                }

                if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                    continue;
                }

                $ms = (int) $value;

                if ($ms < \Beacon\Requests\SlowThresholds::MIN_MS || $ms > \Beacon\Requests\SlowThresholds::MAX_MS) {
                    continue;
                }

                if (preg_match('#^[A-Za-z0-9_./{}: -]{1,191}$#', $key) !== 1) {
                    continue;
                }

                $endpoints[$key] = $ms;
            }
        }

        return new \Beacon\Requests\SlowThresholds($defaultMs, $endpoints);
    }

    public function slowRequestWindowMinutes(): int
    {
        $value = $this->config->get('beacon.requests.slow.window_minutes', 60);

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return 60;
        }

        $minutes = (int) $value;

        return $minutes < 1 || $minutes > 10080 ? 60 : $minutes;
    }

    public function slowRequestMinimumSamples(): int
    {
        $value = $this->config->get('beacon.requests.slow.minimum_samples', 5);

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return 5;
        }

        $samples = (int) $value;

        return $samples < 1 || $samples > 1000 ? 5 : $samples;
    }

    public function slowRequestLimit(): int
    {
        $value = $this->config->get('beacon.requests.slow.default_limit', 25);

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return 25;
        }

        $limit = (int) $value;

        return $limit < 1 || $limit > \Beacon\Requests\RequestQuery::MAX_LIMIT ? 25 : $limit;
    }

    public function slowRequestMaxEndpoints(): int
    {
        $value = $this->config->get('beacon.requests.slow.max_endpoints', 25);

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return 25;
        }

        $max = (int) $value;

        return $max < 1 || $max > 100 ? 25 : $max;
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
