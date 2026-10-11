<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature;

use Beacon\Contracts\Authorizer;
use Beacon\Core\BeaconConfig;
use Beacon\Security\AccessMode;
use Beacon\Security\Capability;
use Beacon\Tests\TestCase;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\Test;

final class SecureDefaultsTest extends TestCase
{
    private const VALID_TOKEN = '0123456789abcdef0123456789abcdef';

    #[Test]
    public function mcp_is_disabled_and_unauthenticated_by_default(): void
    {
        $config = $this->application()->make(BeaconConfig::class);

        $this->assertFalse($config->mcpEnabled());
        $this->assertNull($config->authToken());
        $this->assertSame(['local'], $config->mcpAllowedEnvironments());
        $this->assertFalse($config->mcpAccess()->allowed);
    }

    #[Test]
    public function production_is_not_an_allowed_mcp_environment_by_default(): void
    {
        $config = $this->configFor('production', ['enabled' => true], self::VALID_TOKEN);

        $decision = $config->mcpAccess();

        $this->assertFalse($decision->allowed);
        $this->assertStringContainsString('[production]', $decision->reason);
    }

    #[Test]
    public function mutating_capabilities_are_denied_even_when_explicitly_allowed(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['*', 'cache']);

        $authorizer = $this->application()->make(Authorizer::class);

        $this->assertTrue($authorizer->decide(Capability::read('cache'))->allowed);
        $this->assertFalse($authorizer->decide(new Capability('cache', AccessMode::Write))->allowed);
    }

    #[Test]
    public function mcp_access_requires_every_condition(): void
    {
        $this->assertTrue($this->configFor('local', ['enabled' => true], self::VALID_TOKEN)->mcpAccess()->allowed);

        $this->assertFalse($this->configFor('local', ['enabled' => false], self::VALID_TOKEN)->mcpAccess()->allowed);
        $this->assertFalse($this->configFor('local', ['enabled' => true], null)->mcpAccess()->allowed);
        $this->assertFalse($this->configFor('local', ['enabled' => true], 'too-short')->mcpAccess()->allowed);
        $this->assertFalse($this->configFor('staging', ['enabled' => true], self::VALID_TOKEN)->mcpAccess()->allowed);
        $this->assertFalse($this->configFor('local', ['enabled' => true], self::VALID_TOKEN, beaconEnabled: false)->mcpAccess()->allowed);
    }

    #[Test]
    public function production_access_must_be_explicitly_allowed(): void
    {
        $config = $this->configFor(
            'production',
            ['enabled' => true, 'allowed_environments' => ['production']],
            self::VALID_TOKEN,
        );

        $this->assertTrue($config->mcpAccess()->allowed);
    }

    /**
     * @param array<string, mixed> $mcp
     */
    private function configFor(string $environment, array $mcp, ?string $token, bool $beaconEnabled = true): BeaconConfig
    {
        $defaults = require __DIR__ . '/../../config/beacon.php';

        $defaults['enabled'] = $beaconEnabled;
        $defaults['mcp'] = array_merge($defaults['mcp'], $mcp);
        $defaults['auth']['token'] = $token;

        return new BeaconConfig(new Repository(['beacon' => $defaults]), $environment);
    }
}
