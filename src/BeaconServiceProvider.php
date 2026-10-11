<?php

declare(strict_types=1);

namespace Beacon;

use Beacon\Contracts\Authorizer;
use Beacon\Contracts\Redactor;
use Beacon\Core\Beacon;
use Beacon\Core\BeaconConfig;
use Beacon\Core\InspectorRegistry;
use Beacon\Security\CapabilityAuthorizer;
use Beacon\Security\CapabilityPolicy;
use Beacon\Security\KeyPatternRedactor;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class BeaconServiceProvider extends ServiceProvider
{
    private const CONFIG_PATH = __DIR__ . '/../config/beacon.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'beacon');

        $this->app->singleton(BeaconConfig::class, static fn (Application $app): BeaconConfig => new BeaconConfig(
            $app->make('config'),
            $app->environment(),
        ));

        $this->app->singleton(Redactor::class, static fn (Application $app): Redactor => new KeyPatternRedactor(
            $app->make(BeaconConfig::class)->additionalRedactedKeys(),
        ));

        $this->app->singleton(CapabilityPolicy::class);
        $this->app->singleton(Authorizer::class, CapabilityAuthorizer::class);
        $this->app->singleton(InspectorRegistry::class);
        $this->app->singleton(Beacon::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::CONFIG_PATH => $this->app->configPath('beacon.php'),
            ], ['beacon-config', 'beacon']);
        }
    }
}
