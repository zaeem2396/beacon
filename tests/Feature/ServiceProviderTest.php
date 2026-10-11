<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature;

use Beacon\BeaconServiceProvider;
use Beacon\Contracts\Authorizer;
use Beacon\Contracts\Redactor;
use Beacon\Core\Beacon;
use Beacon\Core\BeaconConfig;
use Beacon\Core\InspectorRegistry;
use Beacon\Security\CapabilityAuthorizer;
use Beacon\Security\KeyPatternRedactor;
use Beacon\Tests\TestCase;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\Test;

final class ServiceProviderTest extends TestCase
{
    #[Test]
    public function it_registers_the_service_provider(): void
    {
        $this->assertArrayHasKey(BeaconServiceProvider::class, $this->application()->getLoadedProviders());
    }

    #[Test]
    public function it_binds_the_core_services_as_singletons(): void
    {
        $this->assertInstanceOf(KeyPatternRedactor::class, $this->application()->make(Redactor::class));
        $this->assertInstanceOf(CapabilityAuthorizer::class, $this->application()->make(Authorizer::class));

        foreach ([BeaconConfig::class, Redactor::class, Authorizer::class, InspectorRegistry::class, Beacon::class] as $abstract) {
            $this->assertSame($this->application()->make($abstract), $this->application()->make($abstract), $abstract);
        }
    }

    #[Test]
    public function it_publishes_the_configuration_file(): void
    {
        $paths = ServiceProvider::pathsToPublish(BeaconServiceProvider::class, 'beacon-config');

        $this->assertCount(1, $paths);
        $this->assertFileExists((string) array_key_first($paths));
        $this->assertSame($this->application()->configPath('beacon.php'), array_values($paths)[0]);
    }

    #[Test]
    public function it_is_registered_for_laravel_package_discovery(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([BeaconServiceProvider::class], data_get($composer, 'extra.laravel.providers'));
    }
}
