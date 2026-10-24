<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Data\EnvironmentInspection;
use Beacon\Data\EnvironmentSetting;
use Beacon\Data\Finding;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Inspectors\Environment\Checks\AppKeyConfigured;
use Beacon\Inspectors\Environment\Checks\AppUrlSchemeInProduction;
use Beacon\Inspectors\Environment\Checks\DebugEnabledInProduction;
use Beacon\Inspectors\Environment\Checks\SessionHttpOnlyInProduction;
use Beacon\Inspectors\Environment\Checks\SessionSameSiteInProduction;
use Beacon\Inspectors\Environment\Checks\SessionSecureCookieInProduction;
use Beacon\Inspectors\EnvironmentInspector;
use Beacon\Security\Principal;
use Beacon\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class EnvironmentInspectorTest extends TestCase
{
    private const SETTING_NAMES = [
        'app.debug',
        'app.timezone',
        'app.locale',
        'app.url',
        'app.key',
        'session.secure',
        'session.http_only',
        'session.same_site',
    ];

    #[Test]
    public function it_collects_allowlisted_metadata_without_application_identity(): void
    {
        $this->production();
        config()->set('app.name', 'Checkout');
        config()->set('app.debug', false);
        config()->set('app.timezone', 'asia/kolkata');
        config()->set('app.locale', 'pt_BR');
        config()->set('app.url', 'https://deploy:url-password-not-in-output@shop.example.com/store?token=abc#frag');
        config()->set('app.key', 'application-key-not-in-output');

        $inspection = $this->inspect();

        $this->assertSame('production', $inspection->metadata->environment);
        $this->assertFalse($inspection->metadata->debug);
        $this->assertSame('asia/kolkata', $inspection->metadata->timezone);
        $this->assertSame('pt_BR', $inspection->metadata->locale);
        $this->assertIsBool($inspection->metadata->configurationCached);
        $this->assertIsBool($inspection->metadata->routesCached);
        $this->assertSame(self::SETTING_NAMES, array_map(
            static fn (EnvironmentSetting $setting): string => $setting->name,
            $inspection->metadata->settings,
        ));
        $this->assertSame('https', $this->setting($inspection, 'app.url')['value']);
        $this->assertSame('sensitive', $this->setting($inspection, 'app.key')['state']);
        $this->assertNull($this->setting($inspection, 'app.key')['value']);

        $json = (string) json_encode($inspection->toArray(), JSON_UNESCAPED_SLASHES);

        foreach (['Checkout', 'url-password-not-in-output', 'application-key-not-in-output', 'shop.example.com', 'token=abc', 'laravel_version', 'php_version', 'beacon_version', 'maintenance_mode'] as $absent) {
            $this->assertStringNotContainsString($absent, $json);
        }
    }

    #[Test]
    public function debug_enabled_in_production_is_a_confirmed_failure(): void
    {
        $this->production();
        config()->set('app.debug', true);

        $finding = $this->finding(DebugEnabledInProduction::ID);

        $this->assertSame('fail', $finding->status->value);
        $this->assertSame('high', $finding->severity->value);
        $this->assertSame('enabled', $finding->evidence[1]->observation->value);
    }

    #[Test]
    public function debug_disabled_in_production_passes(): void
    {
        $this->production();
        config()->set('app.debug', false);

        $this->assertSame('pass', $this->finding(DebugEnabledInProduction::ID)->status->value);
    }

    #[Test]
    public function debug_outside_production_is_not_a_misconfiguration(): void
    {
        config()->set('app.debug', true);

        $finding = $this->finding(DebugEnabledInProduction::ID);

        $this->assertSame('testing', $this->inspect()->metadata->environment);
        $this->assertSame('pass', $finding->status->value);
        $this->assertSame('not_production', $finding->evidence[0]->observation->value);
        $this->assertSame('enabled', $finding->evidence[1]->observation->value);
    }

    #[Test]
    public function a_non_boolean_debug_value_is_unknown_in_production(): void
    {
        $this->production();
        config()->set('app.debug', 'false');

        $inspection = $this->inspect();

        $this->assertNull($inspection->metadata->debug);
        $this->assertSame('invalid', $this->setting($inspection, 'app.debug')['state']);
        $this->assertNull($this->setting($inspection, 'app.debug')['value']);
        $this->assertSame('unknown', $this->finding(DebugEnabledInProduction::ID)->status->value);
        $this->assertNull($this->setting($inspection, 'app.debug')['value']);
    }

    #[Test]
    public function a_missing_application_key_fails_without_revealing_other_secrets(): void
    {
        config()->set('app.key', '   ');
        config()->set('app.previous_keys', ['previous-key-not-in-output']);
        config()->set('database.connections.mysql.password', 'db-password-not-in-output');
        config()->set('mail.mailers.smtp.password', 'smtp-password-not-in-output');
        config()->set('services.mailgun.secret', 'mailgun-secret-not-in-output');

        $inspection = $this->inspect();
        $json = (string) json_encode($inspection->toArray(), JSON_UNESCAPED_SLASHES);

        $this->assertSame('missing', $this->setting($inspection, 'app.key')['state']);
        $this->assertSame('fail', $this->finding(AppKeyConfigured::ID)->status->value);
        $this->assertSame('high', $this->finding(AppKeyConfigured::ID)->severity->value);

        foreach (['previous-key-not-in-output', 'db-password-not-in-output', 'smtp-password-not-in-output', 'mailgun-secret-not-in-output'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    #[Test]
    public function http_in_production_fails_and_https_with_credentials_keeps_only_the_scheme(): void
    {
        $this->production();
        config()->set('app.url', 'http://shop.example.com/admin');

        $this->assertSame('fail', $this->finding(AppUrlSchemeInProduction::ID)->status->value);
        $this->assertSame('medium', $this->finding(AppUrlSchemeInProduction::ID)->severity->value);
        $this->assertSame('http', $this->setting($this->inspect(), 'app.url')['value']);

        config()->set('app.url', 'https://ops:url-password-not-in-output@shop.example.com');

        $inspection = $this->inspect();

        $this->assertSame('pass', $this->finding(AppUrlSchemeInProduction::ID)->status->value);
        $this->assertSame('https', $this->setting($inspection, 'app.url')['value']);
        $this->assertStringNotContainsString('url-password-not-in-output', (string) json_encode($inspection->toArray()));
    }

    #[Test]
    public function session_checks_distinguish_confirmed_risk_and_unknown(): void
    {
        $this->production();
        config()->set('session.secure', false);
        config()->set('session.http_only', false);
        config()->set('session.same_site', 'NONE');

        $this->assertSame('fail', $this->finding(SessionSecureCookieInProduction::ID)->status->value);
        $this->assertSame('fail', $this->finding(SessionHttpOnlyInProduction::ID)->status->value);
        $this->assertSame('risk', $this->finding(SessionSameSiteInProduction::ID)->status->value);
        $this->assertSame('low', $this->finding(SessionSameSiteInProduction::ID)->severity->value);
        $this->assertSame('none', $this->setting($this->inspect(), 'session.same_site')['value']);

        config()->set('session.secure', null);
        config()->set('session.http_only', 'true');
        config()->set('session.same_site', 'everywhere');

        $this->assertSame('risk', $this->finding(SessionSecureCookieInProduction::ID)->status->value);
        $this->assertSame('unknown', $this->finding(SessionHttpOnlyInProduction::ID)->status->value);
        $this->assertSame('unknown', $this->finding(SessionSameSiteInProduction::ID)->status->value);
        $this->assertNull($this->setting($this->inspect(), 'session.http_only')['value']);
        $this->assertStringNotContainsString('everywhere', (string) json_encode($this->inspect()->toArray()));
    }

    #[Test]
    public function invalid_timezone_and_locale_do_not_fail_the_inspection(): void
    {
        config()->set('app.timezone', 'Mars/Olympus_Mons');
        config()->set('app.locale', '../../etc/passwd');

        $inspection = $this->inspect();

        $this->assertNull($inspection->metadata->timezone);
        $this->assertNull($inspection->metadata->locale);
        $this->assertSame('invalid', $this->setting($inspection, 'app.timezone')['state']);
        $this->assertSame('invalid', $this->setting($inspection, 'app.locale')['state']);
        $this->assertStringNotContainsString('Olympus_Mons', (string) json_encode($inspection->toArray()));
        $this->assertStringNotContainsString('passwd', (string) json_encode($inspection->toArray()));
    }

    #[Test]
    public function configuration_and_route_caches_are_booleans_without_paths(): void
    {
        $this->assertFalse($this->inspect()->metadata->configurationCached);
        $this->assertFalse($this->inspect()->metadata->routesCached);

        $configCache = $this->application()->getCachedConfigPath();
        $routeCache = $this->application()->getCachedRoutesPath();
        file_put_contents($configCache, '<?php return [];');
        file_put_contents($routeCache, '<?php return [];');
        $this->application()->forgetInstance('config_loaded_from_cache');
        $this->application()->forgetInstance('routes.cached');

        try {
            $cached = $this->inspect();

            $this->assertTrue($cached->metadata->configurationCached);
            $this->assertTrue($cached->metadata->routesCached);
            $json = (string) json_encode($cached->toArray());
            $this->assertStringNotContainsString($configCache, $json);
            $this->assertStringNotContainsString($routeCache, $json);
        } finally {
            @unlink($configCache);
            @unlink($routeCache);
            $this->application()->forgetInstance('config_loaded_from_cache');
            $this->application()->forgetInstance('routes.cached');
        }
    }

    #[Test]
    public function it_does_not_read_the_env_file_or_the_process_environment(): void
    {
        $path = $this->application()->environmentFilePath();
        $existed = is_file($path);
        $original = $existed ? (string) file_get_contents($path) : null;
        $secret = 'dotenv-secret-not-in-output';

        file_put_contents($path, ($original ?? '') . "\nLEAKED={$secret}\n");
        putenv('APP_DEBUG=true');
        putenv('LEAKED=' . $secret);
        config()->set('app.debug', false);

        try {
            $inspection = $this->inspect();
            $json = (string) json_encode($inspection->toArray());

            $this->assertFalse($inspection->metadata->debug);
            $this->assertStringNotContainsString($secret, $json);
            $this->assertStringNotContainsString('LEAKED', $json);
        } finally {
            putenv('APP_DEBUG');
            putenv('LEAKED');

            if ($existed) {
                file_put_contents($path, (string) $original);
            } else {
                @unlink($path);
            }
        }
    }

    #[Test]
    public function parameters_are_ignored(): void
    {
        $this->assertEquals(
            $this->inspect()->toArray(),
            $this->inspector()->inspect(['keys' => 'app.key,database.connections.mysql.password', 'path' => '/etc/passwd'])->toArray(),
        );
    }

    #[Test]
    public function it_is_registered_under_the_environment_capability(): void
    {
        $registry = $this->application()->make(InspectorRegistry::class);

        $this->assertTrue($registry->enabled(EnvironmentInspector::NAME));
        $this->assertInstanceOf(EnvironmentInspector::class, $registry->get(EnvironmentInspector::NAME));
        $this->assertSame('environment:read', (string) $this->inspector()->capability());
    }

    #[Test]
    public function it_runs_through_beacon_only_when_the_environment_capability_is_allowed(): void
    {
        $beacon = $this->application()->make(Beacon::class);

        try {
            $beacon->inspect(Principal::local(), EnvironmentInspector::NAME);
            $this->fail('The environment capability was allowed by default.');
        } catch (AuthorizationException) {
        }

        config()->set('beacon.security.allowed_capabilities', ['environment']);

        $this->assertSame($this->inspect()->toArray(), $beacon->inspect(Principal::local(), EnvironmentInspector::NAME)->toArray());
    }

    #[Test]
    public function it_can_be_disabled(): void
    {
        config()->set('beacon.inspectors.environment.enabled', false);
        config()->set('beacon.security.allowed_capabilities', ['environment']);

        $this->expectException(InspectorNotFoundException::class);

        $this->application()->make(Beacon::class)->inspect(Principal::local(), EnvironmentInspector::NAME);
    }

    private function production(): void
    {
        $this->application()->detectEnvironment(static fn (): string => 'production');
    }

    private function inspector(): EnvironmentInspector
    {
        return $this->application()->make(EnvironmentInspector::class);
    }

    private function inspect(): EnvironmentInspection
    {
        $inspection = $this->inspector()->inspect();
        $this->assertInstanceOf(EnvironmentInspection::class, $inspection);

        return $inspection;
    }

    private function finding(string $id): Finding
    {
        foreach ($this->inspect()->findings as $finding) {
            if ($finding->id === $id) {
                return $finding;
            }
        }

        $this->fail("Finding [{$id}] was not produced.");
    }

    /**
     * @return array<string, mixed>
     */
    private function setting(EnvironmentInspection $inspection, string $name): array
    {
        $setting = $inspection->metadata->setting($name);
        $this->assertNotNull($setting);

        return $setting->toArray();
    }
}
