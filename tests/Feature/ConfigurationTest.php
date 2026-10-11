<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature;

use Beacon\Core\BeaconConfig;
use Beacon\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ConfigurationTest extends TestCase
{
    #[Test]
    public function it_merges_the_package_configuration(): void
    {
        $this->assertIsArray(config('beacon'));

        foreach (['enabled', 'mcp', 'auth', 'security', 'redaction', 'inspectors'] as $section) {
            $this->assertTrue(config()->has("beacon.{$section}"), $section);
        }
    }

    #[Test]
    public function application_configuration_overrides_package_defaults(): void
    {
        config()->set('beacon.mcp.allowed_environments', ['staging']);

        $this->assertSame(['staging'], $this->beaconConfig()->mcpAllowedEnvironments());
    }

    #[Test]
    public function it_exposes_typed_values(): void
    {
        config()->set('beacon.enabled', 'false');
        config()->set('beacon.mcp.enabled', '1');
        config()->set('beacon.auth.token', '  padded-token  ');
        config()->set('beacon.security.allowed_capabilities', 'application, routes,,');
        config()->set('beacon.redaction.additional_keys', ['iban', 42, '']);

        $config = $this->beaconConfig();

        $this->assertFalse($config->enabled());
        $this->assertTrue($config->mcpEnabled());
        $this->assertSame('padded-token', $config->authToken());
        $this->assertSame(['application', 'routes'], $config->allowedCapabilities());
        $this->assertSame(['iban'], $config->additionalRedactedKeys());
    }

    #[Test]
    public function invalid_security_values_fall_back_to_secure_defaults(): void
    {
        config()->set('beacon.mcp.enabled', 'not-a-boolean');
        config()->set('beacon.auth.token', ['not', 'a', 'string']);
        config()->set('beacon.mcp.allowed_environments', null);

        $config = $this->beaconConfig();

        $this->assertFalse($config->mcpEnabled());
        $this->assertNull($config->authToken());
        $this->assertSame([], $config->mcpAllowedEnvironments());
    }

    #[Test]
    public function invalid_http_values_fall_back_to_secure_defaults(): void
    {
        config()->set('beacon.mcp.http.enabled', 'not-a-boolean');
        config()->set('beacon.mcp.http.require_https', 'not-a-boolean');
        config()->set('beacon.mcp.http.path', 'beacon/mcp?token=x');

        $config = $this->beaconConfig();

        $this->assertFalse($config->mcpHttpEnabled());
        $this->assertTrue($config->mcpHttpRequiresHttps());
        $this->assertSame(BeaconConfig::DEFAULT_HTTP_PATH, $config->mcpHttpPath());
    }

    #[Test]
    public function the_http_path_is_normalized(): void
    {
        config()->set('beacon.mcp.http.path', ' /internal/beacon/ ');

        $this->assertSame('internal/beacon', $this->beaconConfig()->mcpHttpPath());
    }

    #[Test]
    public function only_sufficiently_long_tokens_are_accepted(): void
    {
        config()->set('beacon.auth.token', str_repeat('a', BeaconConfig::MINIMUM_TOKEN_LENGTH));
        config()->set('beacon.auth.previous_token', 'too-short');

        $this->assertSame(['current' => str_repeat('a', BeaconConfig::MINIMUM_TOKEN_LENGTH)], $this->beaconConfig()->acceptedTokens());
    }

    #[Test]
    public function it_reads_inspector_configuration(): void
    {
        config()->set('beacon.inspectors.routes', ['enabled' => false, 'limit' => 50]);

        $config = $this->beaconConfig();

        $this->assertFalse($config->inspectorEnabled('routes'));
        $this->assertSame(['limit' => 50], $config->inspectorOptions('routes'));
        $this->assertTrue($config->inspectorEnabled('unconfigured'));
        $this->assertSame([], $config->inspectorOptions('unconfigured'));
        $namespaces = $config->inspectorOptions('config')['namespaces'] ?? null;

        $this->assertIsArray($namespaces);
        $this->assertContains('app', $namespaces);
    }

    private function beaconConfig(): BeaconConfig
    {
        return $this->application()->make(BeaconConfig::class);
    }
}
