<?php

declare(strict_types=1);

namespace Beacon;

use Beacon\Console\Commands\ApplicationCommand;
use Beacon\Console\Commands\BeaconCommand;
use Beacon\Console\Commands\CheckCommand;
use Beacon\Console\Commands\DatabaseCommand;
use Beacon\Console\Commands\HealthCommand;
use Beacon\Console\Commands\InstallCommand;
use Beacon\Contracts\Authenticator;
use Beacon\Contracts\Authorizer;
use Beacon\Contracts\Redactor;
use Beacon\Core\Beacon;
use Beacon\Core\BeaconConfig;
use Beacon\Core\InspectorRegistry;
use Beacon\Database\LaravelStatusProbe;
use Beacon\Database\StatusProbe;
use Beacon\Health\CacheProbe;
use Beacon\Health\Clock;
use Beacon\Health\DatabaseProbe;
use Beacon\Health\LaravelCacheProbe;
use Beacon\Health\LaravelDatabaseProbe;
use Beacon\Health\SystemClock;
use Beacon\Inspectors\ApplicationInspector;
use Beacon\Inspectors\ConfigurationInspector;
use Beacon\Inspectors\DatabaseInspector;
use Beacon\Inspectors\EnvironmentInspector;
use Beacon\Inspectors\HealthInspector;
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
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(DatabaseProbe::class, LaravelDatabaseProbe::class);
        $this->app->singleton(CacheProbe::class, LaravelCacheProbe::class);
        $this->app->singleton(StatusProbe::class, LaravelStatusProbe::class);

        $this->callAfterResolving(InspectorRegistry::class, static function (InspectorRegistry $inspectors): void {
            $inspectors->register(ApplicationInspector::NAME, ApplicationInspector::class);
            $inspectors->register(EnvironmentInspector::NAME, EnvironmentInspector::class);
            $inspectors->register(RouteInspector::NAME, RouteInspector::class);
            $inspectors->register(ConfigurationInspector::NAME, ConfigurationInspector::class);
            $inspectors->register(HealthInspector::NAME, HealthInspector::class);
            $inspectors->register(DatabaseInspector::NAME, DatabaseInspector::class);
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
                HealthCommand::class,
                DatabaseCommand::class,
                CheckCommand::class,
                InstallCommand::class,
            ]);
        }
    }
}
