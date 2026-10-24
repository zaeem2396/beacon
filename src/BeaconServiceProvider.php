<?php

declare(strict_types=1);

namespace Beacon;

use Beacon\Console\Commands\ApplicationCommand;
use Beacon\Console\Commands\BeaconCommand;
use Beacon\Console\Commands\CheckCommand;
use Beacon\Console\Commands\InstallCommand;
use Beacon\Contracts\Authenticator;
use Beacon\Contracts\Authorizer;
use Beacon\Contracts\Redactor;
use Beacon\Core\Beacon;
use Beacon\Core\BeaconConfig;
use Beacon\Core\InspectorRegistry;
use Beacon\Inspectors\ApplicationInspector;
use Beacon\Inspectors\EnvironmentInspector;
use Beacon\Inspectors\RouteInspector;
use Beacon\Mcp\BeaconMcpServiceProvider;
use Beacon\Security\BearerTokenAuthenticator;
use Beacon\Security\CapabilityAuthorizer;
use Beacon\Security\CapabilityPolicy;
use Beacon\Security\CapabilityRegistry;
use Beacon\Security\KeyPatternRedactor;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class BeaconServiceProvider extends ServiceProvider
{
    public const CONFIG_TAG = 'beacon-config';

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

        $this->app->singleton(CapabilityRegistry::class);
        $this->app->singleton(CapabilityPolicy::class);
        $this->app->singleton(Authorizer::class, CapabilityAuthorizer::class);
        $this->app->singleton(Authenticator::class, BearerTokenAuthenticator::class);
        $this->app->singleton(InspectorRegistry::class);
        $this->app->singleton(Beacon::class);

        $this->callAfterResolving(InspectorRegistry::class, static function (InspectorRegistry $inspectors): void {
            $inspectors->register(ApplicationInspector::NAME, ApplicationInspector::class);
            $inspectors->register(EnvironmentInspector::NAME, EnvironmentInspector::class);
            $inspectors->register(RouteInspector::NAME, RouteInspector::class);
        });

        $this->app->register(BeaconMcpServiceProvider::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::CONFIG_PATH => $this->app->configPath('beacon.php'),
            ], [self::CONFIG_TAG, 'beacon']);

            $this->commands([
                BeaconCommand::class,
                ApplicationCommand::class,
                CheckCommand::class,
                InstallCommand::class,
            ]);
        }
    }
}
