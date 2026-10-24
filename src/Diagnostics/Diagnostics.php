<?php

declare(strict_types=1);

namespace Beacon\Diagnostics;

use Beacon\BeaconServiceProvider;
use Beacon\Contracts\Authenticator;
use Beacon\Contracts\Authorizer;
use Beacon\Contracts\Redactor;
use Beacon\Core\BeaconConfig;
use Beacon\Core\InspectorRegistry;
use Beacon\Core\Version;
use Beacon\Diagnostics\DiagnosticStatus as Status;
use Beacon\Mcp\McpRegistry;
use Beacon\Mcp\ServerRegistration;
use Beacon\Security\AccessMode;
use Beacon\Security\Capability;
use Beacon\Security\CapabilityPolicy;
use Beacon\Security\CapabilityRegistry;
use Beacon\Security\Principal;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Registrar;

/**
 * Read-only self-diagnostics of Beacon's installation and configuration.
 *
 * Checks never connect to external services, never change state and never
 * report credentials or configuration values that could be secret.
 */
final class Diagnostics
{
    private const BOOLEAN_KEYS = [
        'enabled',
        'mcp.enabled',
        'mcp.http.enabled',
        'mcp.http.require_https',
    ];

    private const LIST_KEYS = [
        'mcp.allowed_environments',
        'security.allowed_capabilities',
        'redaction.additional_keys',
    ];

    private const STRING_KEYS = [
        'mcp.http.path',
        'auth.token',
        'auth.previous_token',
    ];

    public function __construct(
        private readonly Application $app,
        private readonly Repository $repository,
        private readonly BeaconConfig $config,
        private readonly ServerRegistration $registration,
        private readonly CapabilityPolicy $policy,
        private readonly CapabilityRegistry $capabilities,
        private readonly Redactor $redactor,
        private readonly InspectorRegistry $inspectors,
        private readonly McpRegistry $mcp,
    ) {
    }

    public function run(): DiagnosticReport
    {
        $checks = [
            'package' => $this->package(...),
            'config' => $this->configuration(...),
            'application' => $this->application(...),
            'mcp' => $this->mcpState(...),
            'mcp.sdk' => $this->mcpSdk(...),
            'mcp.server' => $this->mcpServer(...),
            'mcp.http' => $this->mcpHttp(...),
            'auth' => $this->authentication(...),
            'security.read_only' => $this->readOnly(...),
            'security.capabilities' => $this->allowedCapabilities(...),
            'security.redaction' => $this->redaction(...),
            'inspectors' => $this->inspectorsState(...),
            'mcp.tools' => $this->mcpTools(...),
        ];

        $results = [];

        foreach ($checks as $id => $check) {
            try {
                $results[] = $check();
            } catch (\Throwable) {
                $results[] = new CheckResult($id, $id, Status::Fail, 'This check could not be completed.', 'Run the check again with a debugger or inspect the application log.');
            }
        }

        return new DiagnosticReport($results);
    }

    private function package(): CheckResult
    {
        $services = [BeaconConfig::class, Redactor::class, Authorizer::class, Authenticator::class];

        if (array_filter($services, fn (string $service): bool => ! $this->app->bound($service)) !== []) {
            return new CheckResult('package', 'Beacon package', Status::Fail, 'The Beacon service provider is not registered.', 'Make sure package discovery is enabled or register ' . BeaconServiceProvider::class . '.');
        }

        return new CheckResult('package', 'Beacon package', Status::Pass, sprintf('Beacon %s is installed and its service provider is registered.', Version::current()));
    }

    private function configuration(): CheckResult
    {
        $raw = $this->repository->get('beacon');

        if (! is_array($raw)) {
            return new CheckResult('config', 'Configuration', Status::Misconfigured, 'The "beacon" configuration is missing or not an array.', 'Run "php artisan beacon:install" or remove the invalid config/beacon.php.');
        }

        $invalid = [];

        foreach (self::BOOLEAN_KEYS as $key) {
            $value = data_get($raw, $key);

            if ($value !== null && ! is_bool($value) && filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === null) {
                $invalid[] = $key;
            }
        }

        foreach (self::LIST_KEYS as $key) {
            $value = data_get($raw, $key);

            if ($value !== null && ! is_string($value) && ! (is_array($value) && array_filter($value, is_string(...)) === $value)) {
                $invalid[] = $key;
            }
        }

        foreach (self::STRING_KEYS as $key) {
            $value = data_get($raw, $key);

            if ($value !== null && ! is_string($value)) {
                $invalid[] = $key;
            }
        }

        $path = data_get($raw, 'mcp.http.path');

        if (is_string($path) && trim($path, " \t\n\r\0\x0B/") !== $this->config->mcpHttpPath()) {
            $invalid[] = 'mcp.http.path';
        }

        $source = file_exists($this->app->configPath('beacon.php')) ? 'config/beacon.php' : 'package defaults (config not published)';
        $cached = method_exists($this->app, 'configurationIsCached') && $this->app->configurationIsCached() ? ' Configuration is cached; run "php artisan config:clear" after changes.' : '';

        if ($invalid !== []) {
            return new CheckResult(
                'config',
                'Configuration',
                Status::Misconfigured,
                sprintf('Invalid values for: %s. Beacon falls back to secure defaults for them.', implode(', ', array_unique($invalid))),
                'Fix the listed keys in config/beacon.php or the matching BEACON_* environment variables.' . $cached,
            );
        }

        return new CheckResult('config', 'Configuration', Status::Pass, "Loaded from {$source}." . $cached);
    }

    private function application(): CheckResult
    {
        $environment = $this->config->environment();
        $debug = (bool) $this->repository->get('app.debug', false);

        if ($debug && $environment === 'production') {
            return new CheckResult('application', 'Application', Status::Warn, 'APP_DEBUG is enabled in the production environment.', 'Disable APP_DEBUG in production. Beacon sanitizes MCP errors, but debug mode exposes details elsewhere.');
        }

        return new CheckResult('application', 'Application', Status::Pass, "Environment [{$environment}], debug mode " . ($debug ? 'on' : 'off') . '.');
    }

    private function mcpState(): CheckResult
    {
        $name = 'MCP access';

        if (! $this->config->enabled()) {
            return new CheckResult('mcp', $name, Status::Disabled, 'Beacon is disabled (BEACON_ENABLED=false). Every capability is denied.');
        }

        if (! $this->config->mcpEnabled()) {
            return new CheckResult('mcp', $name, Status::Disabled, 'MCP is disabled (default).', 'Set BEACON_MCP_ENABLED=true to enable it.');
        }

        if (! $this->tokenIsUsable($this->config->authToken())) {
            return new CheckResult('mcp', $name, Status::Misconfigured, 'MCP is enabled but no valid authentication token is configured, so it stays unavailable.', 'See the "auth" check.');
        }

        $environment = $this->config->environment();

        if (! in_array($environment, $this->config->mcpAllowedEnvironments(), true)) {
            return new CheckResult('mcp', $name, Status::Disabled, "MCP is enabled but not allowed in the [{$environment}] environment.", 'Add the environment to BEACON_MCP_ENVIRONMENTS to allow it.');
        }

        return new CheckResult('mcp', $name, Status::Pass, "MCP is enabled and allowed in the [{$environment}] environment.");
    }

    private function mcpSdk(): CheckResult
    {
        $name = 'laravel/mcp';

        if (! class_exists(Server::class)) {
            return $this->mcpWanted()
                ? new CheckResult('mcp.sdk', $name, Status::Misconfigured, 'MCP is enabled but laravel/mcp is not installed.', 'Run "composer require laravel/mcp".')
                : new CheckResult('mcp.sdk', $name, Status::Disabled, 'laravel/mcp is not installed. It is only required to enable MCP.', 'Run "composer require laravel/mcp" before enabling MCP.');
        }

        if (! $this->app->bound(Registrar::class)) {
            return new CheckResult('mcp.sdk', $name, $this->mcpWanted() ? Status::Misconfigured : Status::Disabled, 'laravel/mcp is installed but its service provider is not registered.', 'Enable package discovery for laravel/mcp or register Laravel\Mcp\Server\McpServiceProvider.');
        }

        return new CheckResult('mcp.sdk', $name, Status::Pass, 'laravel/mcp is installed and registered.');
    }

    private function mcpServer(): CheckResult
    {
        $name = 'MCP server (stdio)';
        $decision = $this->registration->decide();

        if (! $decision->allowed) {
            return new CheckResult('mcp.server', $name, Status::Disabled, 'The Beacon MCP server is not registered because MCP is not available.', 'See the "mcp" and "mcp.sdk" checks.');
        }

        $registered = $this->registration->registered()
            && $this->app->make(Registrar::class)->getLocalServer(ServerRegistration::HANDLE) !== null;

        if (! $registered) {
            return new CheckResult('mcp.server', $name, Status::Fail, 'MCP is available but the Beacon server is not registered with laravel/mcp.', 'Make sure Beacon\'s service provider boots after laravel/mcp\'s.');
        }

        return new CheckResult('mcp.server', $name, Status::Pass, 'Registered as "beacon". Start it with "php artisan mcp:start beacon".');
    }

    private function mcpHttp(): CheckResult
    {
        $name = 'MCP HTTP transport';

        if (! $this->config->mcpHttpEnabled()) {
            return new CheckResult('mcp.http', $name, Status::Disabled, 'The HTTP transport is disabled (default). Only local stdio access is possible.');
        }

        if (! $this->registration->decideHttp()->allowed) {
            return new CheckResult('mcp.http', $name, Status::Disabled, 'The HTTP transport is enabled, but MCP is not available, so no endpoint is registered.', 'See the "mcp" check.');
        }

        $path = $this->config->mcpHttpPath();

        if (! $this->registration->httpRegistered() || $this->app->make(Registrar::class)->getWebServer($path) === null) {
            return new CheckResult('mcp.http', $name, Status::Fail, 'The HTTP transport should be registered but no route was found.');
        }

        if (! $this->config->mcpHttpRequiresHttps()) {
            return new CheckResult('mcp.http', $name, Status::Warn, "POST /{$path} accepts plain HTTP. Bearer tokens can be intercepted.", 'Set BEACON_MCP_HTTP_REQUIRE_HTTPS=true outside local development.');
        }

        return new CheckResult('mcp.http', $name, Status::Pass, "POST /{$path} requires a bearer token over HTTPS.");
    }

    private function authentication(): CheckResult
    {
        $name = 'Authentication';
        $token = $this->repository->get('beacon.auth.token');
        $previous = $this->config->acceptedTokens()['previous'] ?? null;
        $rawPrevious = $this->repository->get('beacon.auth.previous_token');
        $mcpWanted = $this->mcpWanted();
        $hint = 'Generate a token with: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;" and set BEACON_MCP_TOKEN.';

        if (! is_string($token) || trim($token) === '') {
            return new CheckResult('auth', $name, $mcpWanted ? Status::Misconfigured : Status::Disabled, 'No MCP token is configured (BEACON_MCP_TOKEN).', $hint);
        }

        if (! $this->tokenIsUsable($token)) {
            return new CheckResult('auth', $name, Status::Misconfigured, sprintf('BEACON_MCP_TOKEN is shorter than %d characters and is rejected.', BeaconConfig::MINIMUM_TOKEN_LENGTH), $hint);
        }

        if (is_string($rawPrevious) && trim($rawPrevious) !== '' && $previous === null) {
            return new CheckResult('auth', $name, Status::Misconfigured, sprintf('BEACON_MCP_PREVIOUS_TOKEN is shorter than %d characters and is ignored.', BeaconConfig::MINIMUM_TOKEN_LENGTH), 'Remove it, or set it to the full previous token during rotation.');
        }

        if ($previous !== null) {
            return new CheckResult('auth', $name, Status::Warn, 'A token is configured and a previous token is still accepted (rotation in progress).', 'Remove BEACON_MCP_PREVIOUS_TOKEN once every client uses the new token.');
        }

        return new CheckResult('auth', $name, Status::Pass, 'A token of sufficient length is configured.');
    }

    private function readOnly(): CheckResult
    {
        $name = 'Read-only enforcement';
        $probe = new Capability('beacon-diagnostics', AccessMode::Write);

        if ($this->policy->evaluate(Principal::local(), $probe)->allowed) {
            return new CheckResult('security.read_only', $name, Status::Fail, 'A mutating capability was allowed. Beacon must be read-only.');
        }

        return new CheckResult('security.read_only', $name, Status::Pass, 'Mutating capabilities are denied and cannot be registered.');
    }

    private function allowedCapabilities(): CheckResult
    {
        $name = 'Capability allowlist';
        $allowed = $this->config->allowedCapabilities();
        $registered = array_map(
            static fn (string $capability): string => explode(':', $capability, 2)[0],
            array_keys($this->capabilities->all()),
        );
        $unknown = array_values(array_diff($allowed, [...$registered, '*']));

        if ($unknown !== []) {
            return new CheckResult('security.capabilities', $name, Status::Warn, sprintf('Ignored unregistered capabilities: %s.', implode(', ', $unknown)), sprintf('Registered capabilities: %s.', implode(', ', $registered)));
        }

        if (in_array('*', $allowed, true)) {
            return new CheckResult('security.capabilities', $name, Status::Warn, 'Every registered capability is allowed ("*").', 'List only the capabilities you need in BEACON_ALLOWED_CAPABILITIES.');
        }

        if ($allowed === []) {
            return new CheckResult('security.capabilities', $name, Status::Pass, 'Default deny: no capabilities are allowed, so authenticated clients cannot run anything.', 'Allow capabilities with BEACON_ALLOWED_CAPABILITIES, e.g. application,routes.');
        }

        return new CheckResult('security.capabilities', $name, Status::Pass, sprintf('Allowed: %s.', implode(', ', $allowed)));
    }

    private function redaction(): CheckResult
    {
        $name = 'Redaction';

        if (! $this->redactor->isSensitiveKey('APP_KEY') || ! $this->redactor->isSensitiveValue('mysql://user:secret@localhost/app')) {
            return new CheckResult('security.redaction', $name, Status::Fail, 'Built-in redaction rules are not active.');
        }

        $additional = count($this->config->additionalRedactedKeys());

        return new CheckResult('security.redaction', $name, Status::Pass, sprintf('Built-in redaction rules are active, plus %d additional key(s).', $additional));
    }

    private function inspectorsState(): CheckResult
    {
        $enabled = $this->inspectors->names();

        if ($enabled === []) {
            return new CheckResult('inspectors', 'Inspectors', Status::Disabled, 'Every inspector is disabled.', 'Enable inspectors under "inspectors" in config/beacon.php.');
        }

        return new CheckResult('inspectors', 'Inspectors', Status::Pass, sprintf('Enabled: %s.', implode(', ', $enabled)));
    }

    private function mcpTools(): CheckResult
    {
        $tools = count($this->mcp->tools());
        $others = count($this->mcp->resources()) + count($this->mcp->prompts());

        if ($tools + $others === 0) {
            return new CheckResult('mcp.tools', 'MCP tools', Status::Disabled, 'No MCP tools are registered. Beacon\'s tools require laravel/mcp.');
        }

        return new CheckResult(
            'mcp.tools',
            'MCP tools',
            Status::Pass,
            sprintf('%d tool(s)', $tools) . ($others > 0 ? sprintf(' and %d resource(s) or prompt(s)', $others) : '') . ' registered. Each runs only when its capability is allowed.',
        );
    }

    private function mcpWanted(): bool
    {
        return $this->config->enabled() && $this->config->mcpEnabled();
    }

    private function tokenIsUsable(mixed $token): bool
    {
        return is_string($token) && strlen(trim($token)) >= BeaconConfig::MINIMUM_TOKEN_LENGTH;
    }
}
