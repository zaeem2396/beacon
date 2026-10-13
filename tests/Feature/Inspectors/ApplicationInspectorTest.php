<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Core\Version;
use Beacon\Data\ApplicationInfo;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Inspectors\ApplicationInspector;
use Beacon\Security\Principal;
use Beacon\Tests\Fixtures\FakeMaintenanceMode;
use Beacon\Tests\TestCase;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class ApplicationInspectorTest extends TestCase
{
    /**
     * Configuration that must never appear in the application information.
     */
    private const SECRETS = [
        'app.key' => 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=',
        'app.previous_keys' => ['base64:cHJldmlvdXMta2V5LXRoYXQtbXVzdC1uZXZlci1sZWFr'],
        'database.connections.mysql.password' => 'db-password-never-returned',
        'database.connections.mysql.username' => 'db-username-never-returned',
        'services.stripe.secret' => 'sk_live_51NeverReturnedStripeSecret',
        'mail.mailers.smtp.password' => 'smtp-password-never-returned',
        'session.cookie' => 'session-cookie-name-never-returned',
        'app.cipher' => 'AES-256-CBC-never-returned',
    ];

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidNames(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'not a string' => [['Checkout']];
        yield 'control characters' => ["Check\nout"];
        yield 'invalid utf-8' => ["Check\xB1out"];
        yield 'too long' => [str_repeat('a', ApplicationInspector::MAX_NAME_LENGTH + 1)];
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonBooleanDebugValues(): iterable
    {
        yield 'missing' => [null];
        yield 'string false' => ['false'];
        yield 'string true' => ['true'];
        yield 'integer' => [1];
        yield 'array' => [[true]];
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidTimezones(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'unknown' => ['Mars/Olympus_Mons'];
        yield 'not a string' => [3600];
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidLocales(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'single letter' => ['e'];
        yield 'path traversal' => ['../../etc/passwd'];
        yield 'whitespace' => ['en US'];
        yield 'not a string' => [['en']];
    }

    #[Test]
    public function it_collects_the_approved_metadata_from_the_running_application(): void
    {
        config()->set('app.name', 'Checkout');
        config()->set('app.debug', false);
        config()->set('app.url', 'https://shop.example.com');
        config()->set('app.timezone', 'Europe/Amsterdam');
        config()->set('app.locale', 'nl');
        config()->set('app.fallback_locale', 'en');

        $version = Version::current();

        $this->assertSame([
            'schema_version' => ApplicationInfo::SCHEMA_VERSION,
            'name' => 'Checkout',
            'environment' => 'testing',
            'debug' => false,
            'url' => 'https://shop.example.com',
            'timezone' => 'Europe/Amsterdam',
            'locale' => 'nl',
            'fallback_locale' => 'en',
            'maintenance_mode' => false,
            'laravel_version' => $this->application()->version(),
            'php_version' => PHP_VERSION,
            'beacon_version' => $version === Version::UNKNOWN ? null : $version,
        ], $this->inspect()->toArray());
    }

    #[Test]
    public function it_reports_the_actual_environment_and_debug_mode(): void
    {
        foreach (['production', 'staging', 'local'] as $environment) {
            $this->application()->detectEnvironment(static fn (): string => $environment);

            foreach ([true, false] as $debug) {
                config()->set('app.debug', $debug);

                $info = $this->inspect();

                $this->assertSame($environment, $info->environment);
                $this->assertSame($debug, $info->debug);
            }
        }
    }

    #[Test]
    #[DataProvider('nonBooleanDebugValues')]
    public function debug_mode_is_unknown_unless_it_is_a_real_boolean(mixed $value): void
    {
        config()->set('app.debug', $value);

        $this->assertNull($this->inspect()->debug);
    }

    #[Test]
    #[DataProvider('invalidNames')]
    public function invalid_application_names_are_reported_as_unavailable(mixed $value): void
    {
        config()->set('app.name', $value);

        $this->assertNull($this->inspect()->name);
    }

    #[Test]
    public function application_names_are_trimmed_and_may_contain_unicode(): void
    {
        config()->set('app.name', '  Café Checkout  ');

        $this->assertSame('Café Checkout', $this->inspect()->name);
    }

    #[Test]
    public function the_application_url_is_sanitized(): void
    {
        config()->set('app.url', 'https://deploy:hunter2@shop.example.com:8443/store/?signature=abc123#token=xyz');

        $info = $this->inspect();

        $this->assertSame('https://shop.example.com:8443/store', $info->url);
        $this->assertStringNotContainsString('hunter2', (string) json_encode($info->toArray()));
    }

    #[Test]
    public function an_invalid_application_url_is_reported_as_unavailable(): void
    {
        foreach ([null, '', 'shop.example.com', 'ftp://shop.example.com', ['https://shop.example.com']] as $url) {
            config()->set('app.url', $url);

            $this->assertNull($this->inspect()->url);
        }
    }

    #[Test]
    #[DataProvider('invalidTimezones')]
    public function invalid_timezones_are_reported_as_unavailable(mixed $value): void
    {
        config()->set('app.timezone', $value);

        $this->assertNull($this->inspect()->timezone);
    }

    #[Test]
    public function valid_timezones_are_reported_as_configured(): void
    {
        config()->set('app.timezone', ' UTC ');
        $this->assertSame('UTC', $this->inspect()->timezone);

        config()->set('app.timezone', 'Asia/Kolkata');
        $this->assertSame('Asia/Kolkata', $this->inspect()->timezone);

        config()->set('app.timezone', '+05:30');
        $this->assertSame('+05:30', $this->inspect()->timezone);
    }

    #[Test]
    #[DataProvider('invalidLocales')]
    public function invalid_locales_are_reported_as_unavailable(mixed $value): void
    {
        config()->set('app.locale', $value);
        config()->set('app.fallback_locale', $value);

        $info = $this->inspect();

        $this->assertNull($info->locale);
        $this->assertNull($info->fallbackLocale);
    }

    #[Test]
    public function regional_locales_are_accepted(): void
    {
        config()->set('app.locale', 'pt_BR');
        config()->set('app.fallback_locale', 'zh-Hant-TW');

        $info = $this->inspect();

        $this->assertSame('pt_BR', $info->locale);
        $this->assertSame('zh-Hant-TW', $info->fallbackLocale);
    }

    #[Test]
    public function it_reports_maintenance_mode(): void
    {
        $this->application()->instance(MaintenanceMode::class, new FakeMaintenanceMode(true));
        $this->assertTrue($this->inspect()->maintenanceMode);

        $this->application()->instance(MaintenanceMode::class, new FakeMaintenanceMode(false));
        $this->assertFalse($this->inspect()->maintenanceMode);
    }

    #[Test]
    public function an_unreadable_maintenance_mode_is_unknown_and_does_not_fail_the_inspection(): void
    {
        $this->application()->instance(MaintenanceMode::class, new FakeMaintenanceMode(null));

        $info = $this->inspect();

        $this->assertNull($info->maintenanceMode);
        $this->assertSame($this->application()->version(), $info->laravelVersion);
        $this->assertStringNotContainsString('maintenance-secret', (string) json_encode($info->toArray()));
    }

    #[Test]
    public function parameters_are_ignored(): void
    {
        $this->assertEquals(
            $this->inspect()->toArray(),
            $this->inspector()->inspect(['include' => 'app.key', 'path' => '/etc/passwd'])->toArray(),
        );
    }

    #[Test]
    public function it_never_returns_secrets_unrelated_configuration_or_filesystem_paths(): void
    {
        foreach (self::SECRETS as $key => $value) {
            config()->set($key, $value);
        }

        $_SERVER['BEACON_TEST_SERVER_SECRET'] = 'server-variable-never-returned';
        putenv('BEACON_TEST_ENV_SECRET=environment-variable-never-returned');

        try {
            $json = (string) json_encode($this->inspect()->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } finally {
            unset($_SERVER['BEACON_TEST_SERVER_SECRET']);
            putenv('BEACON_TEST_ENV_SECRET');
        }

        foreach (self::SECRETS as $value) {
            foreach ((array) $value as $secret) {
                $this->assertStringNotContainsString($secret, $json);
            }
        }

        $this->assertStringNotContainsString('never-returned', $json);
        $this->assertStringNotContainsString($this->application()->basePath(), $json);
        $this->assertStringNotContainsString((string) realpath(__DIR__ . '/../../..'), $json);
    }

    #[Test]
    public function it_is_registered_under_the_application_capability(): void
    {
        $registry = $this->application()->make(InspectorRegistry::class);

        $this->assertTrue($registry->enabled(ApplicationInspector::NAME));
        $this->assertInstanceOf(ApplicationInspector::class, $registry->get(ApplicationInspector::NAME));
        $this->assertSame('application:read', (string) $this->inspector()->capability());
    }

    #[Test]
    public function it_runs_through_beacon_only_when_the_application_capability_is_allowed(): void
    {
        $beacon = $this->application()->make(Beacon::class);

        try {
            $beacon->inspect(Principal::local(), ApplicationInspector::NAME);
            $this->fail('The application capability was allowed by default.');
        } catch (AuthorizationException) {
        }

        config()->set('beacon.security.allowed_capabilities', ['application']);

        $this->assertSame($this->inspect()->toArray(), $beacon->inspect(Principal::local(), ApplicationInspector::NAME)->toArray());
    }

    #[Test]
    public function it_can_be_disabled(): void
    {
        config()->set('beacon.inspectors.application.enabled', false);
        config()->set('beacon.security.allowed_capabilities', ['application']);

        $this->expectException(InspectorNotFoundException::class);

        $this->application()->make(Beacon::class)->inspect(Principal::local(), ApplicationInspector::NAME);
    }

    private function inspector(): ApplicationInspector
    {
        return $this->application()->make(ApplicationInspector::class);
    }

    private function inspect(): ApplicationInfo
    {
        $info = $this->inspector()->inspect();
        $this->assertInstanceOf(ApplicationInfo::class, $info);

        return $info;
    }
}
