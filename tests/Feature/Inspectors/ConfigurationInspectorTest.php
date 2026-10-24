<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Core\Payload;
use Beacon\Data\ConfigurationCatalog;
use Beacon\Data\ConfigurationInspection;
use Beacon\Data\ConfigurationSetting;
use Beacon\Data\Finding;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Exceptions\InvalidConfigurationQueryException;
use Beacon\Inspectors\Configuration\ConfigurationQuery;
use Beacon\Inspectors\ConfigurationInspector;
use Beacon\Security\KeyPatternRedactor;
use Beacon\Security\Principal;
use Beacon\Tests\TestCase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class ConfigurationInspectorTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const SECRETS = [
        'application-key-not-in-output',
        'url-secret-not-in-output',
        'config-host-not-in-output.example',
        'db-host-not-in-output.example',
        'secret-database-not-in-output',
        'secret-user-not-in-output',
        'database-password-not-in-output',
        'mysql-socket-not-in-output',
        'connection-secret-not-in-output',
        'redis-password-not-in-output',
        'cache-prefix-not-in-output',
        'queue-key-not-in-output',
        'sqs-secret-not-in-output',
        'queue-url-not-in-output',
        'queue-arn-not-in-output',
        'mail-password-not-in-output',
        'mail-username-not-in-output',
        'smtp-host-not-in-output.example',
        'disk-key-not-in-output',
        'disk-secret-not-in-output',
        'disk-region-not-in-output',
        'disk-bucket-not-in-output',
        'disk-endpoint-not-in-output',
        'disk-root-not-in-output',
        'sqlite-path-not-in-output',
        'slack-webhook-not-in-output',
        'private-key-not-in-output',
        'BEGIN PRIVATE KEY',
        'session-domain-not-in-output.example',
        'session-path-not-in-output',
        'stripe-secret-not-in-output',
        'memcached-password-not-in-output',
    ];

    #[Test]
    public function it_reports_allowlisted_metadata_for_every_category_and_drops_secrets(): void
    {
        $this->installSecrets();

        $inspection = $this->inspect();
        $json = $this->encoded($inspection);

        $this->assertSame(ConfigurationCatalog::IDS, array_map(
            static fn (ConfigurationSetting $setting): string => $setting->id,
            $inspection->settings,
        ));
        $this->assertSame('AES-256-CBC', $this->setting($inspection, 'app.cipher')->value);
        $this->assertSame('file', $this->setting($inspection, 'app.maintenance_driver')->value);
        $this->assertSame('sensitive', $this->setting($inspection, 'app.key')->state->value);
        $this->assertNull($this->setting($inspection, 'app.key')->value);

        $this->assertSame('mysql', $this->setting($inspection, 'database.default')->value);
        $this->assertSame('registered', $this->setting($inspection, 'database.connection')->value);
        $this->assertSame('mysql', $this->setting($inspection, 'database.driver')->value);
        $this->assertSame('sensitive', $this->setting($inspection, 'database.password')->state->value);
        $this->assertSame('sensitive', $this->setting($inspection, 'database.url')->state->value);
        $this->assertNull($this->setting($inspection, 'database.password')->value);
        $this->assertNull($this->setting($inspection, 'database.url')->value);

        $this->assertSame('redis', $this->setting($inspection, 'cache.default')->value);
        $this->assertSame('registered', $this->setting($inspection, 'cache.store')->value);
        $this->assertSame('redis', $this->setting($inspection, 'cache.driver')->value);

        $this->assertSame('sqs', $this->setting($inspection, 'queue.default')->value);
        $this->assertSame('registered', $this->setting($inspection, 'queue.connection')->value);
        $this->assertSame('sqs', $this->setting($inspection, 'queue.driver')->value);
        $this->assertSame('sensitive', $this->setting($inspection, 'queue.name')->state->value);
        $this->assertNull($this->setting($inspection, 'queue.name')->value);

        $this->assertSame('redis', $this->setting($inspection, 'session.driver')->value);
        $this->assertTrue($this->setting($inspection, 'session.secure')->value);
        $this->assertTrue($this->setting($inspection, 'session.http_only')->value);
        $this->assertSame('lax', $this->setting($inspection, 'session.same_site')->value);
        $this->assertFalse($this->setting($inspection, 'session.encrypt')->value);
        $this->assertSame('laravel_session', $this->setting($inspection, 'session.cookie')->value);

        $this->assertSame('smtp', $this->setting($inspection, 'mail.default')->value);
        $this->assertSame('registered', $this->setting($inspection, 'mail.mailer')->value);
        $this->assertSame('smtp', $this->setting($inspection, 'mail.transport')->value);
        $this->assertSame('smtp', $this->setting($inspection, 'mail.scheme')->value);
        $this->assertSame('sensitive', $this->setting($inspection, 'mail.username')->state->value);
        $this->assertSame('sensitive', $this->setting($inspection, 'mail.password')->state->value);
        $this->assertNull($this->setting($inspection, 'mail.username')->value);
        $this->assertNull($this->setting($inspection, 'mail.password')->value);

        $this->assertSame('s3', $this->setting($inspection, 'filesystem.default')->value);
        $this->assertSame('registered', $this->setting($inspection, 'filesystem.disk')->value);
        $this->assertSame('s3', $this->setting($inspection, 'filesystem.driver')->value);
        $this->assertSame('private', $this->setting($inspection, 'filesystem.visibility')->value);
        $this->assertSame('sensitive', $this->setting($inspection, 'filesystem.credentials')->state->value);
        $this->assertNull($this->setting($inspection, 'filesystem.credentials')->value);

        $this->assertSame('stderr', $this->setting($inspection, 'logging.default')->value);
        $this->assertSame('registered', $this->setting($inspection, 'logging.channel')->value);
        $this->assertSame('monolog', $this->setting($inspection, 'logging.driver')->value);
        $this->assertSame('debug', $this->setting($inspection, 'logging.level')->value);
        $this->assertSame('Monolog\\Handler\\StreamHandler', $this->setting($inspection, 'logging.handler')->value);
        $this->assertSame('missing', $this->setting($inspection, 'logging.webhook')->state->value);

        $this->assertStringNotContainsString('[REDACTED]', $json);
        $this->assertSecretsAbsent($json);
        $this->assertSecretsAbsent((string) Payload::fromData($inspection, new KeyPatternRedactor())->toJson());
        $this->assertStringNotContainsString($this->application()->basePath(), $json);
    }

    #[Test]
    public function framework_defaults_do_not_include_filesystem_paths(): void
    {
        $inspection = $this->inspect();
        $json = $this->encoded($inspection);

        $this->assertCount(38, $inspection->settings);
        $this->assertSame(ConfigurationCatalog::IDS, array_map(
            static fn (ConfigurationSetting $setting): string => $setting->id,
            $inspection->settings,
        ));
        $this->assertStringNotContainsString($this->application()->basePath(), $json);
        $this->assertStringNotContainsString('[REDACTED]', $json);

        foreach ($inspection->findings as $finding) {
            $this->assertSame('pass', $finding->status->value, $finding->id);
        }
    }

    #[Test]
    public function a_connection_name_that_matches_its_password_is_withheld(): void
    {
        config()->set('database.default', 'sqldatabase');
        config()->set('database.connections.sqldatabase', [
            'driver' => 'mysql',
            'username' => 'sqldatabase',
            'password' => 'sqldatabase',
        ]);

        $inspection = $this->inspect();

        $this->assertSame('sensitive', $this->setting($inspection, 'database.default')->state->value);
        $this->assertNull($this->setting($inspection, 'database.default')->value);
        $this->assertSame('registered', $this->setting($inspection, 'database.connection')->value);
        $this->assertSame('mysql', $this->setting($inspection, 'database.driver')->value);
        $this->assertSame('pass', $this->finding($inspection, 'database_connection')->status->value);
        $this->assertStringNotContainsString('sqldatabase', $this->encoded($inspection));
    }

    #[Test]
    public function a_dotted_connection_name_is_not_followed(): void
    {
        config()->set('database.connections.reports', [
            'driver' => 'mysql',
            'password' => 'database-password-not-in-output',
            'primary' => 'nested-secret-not-in-output',
        ]);
        config()->set('database.default', 'reports.primary');

        try {
            $inspection = $this->inspect();

            $this->assertSame('sensitive', $this->setting($inspection, 'database.default')->state->value);
            $this->assertNull($this->setting($inspection, 'database.driver')->value);
            $this->assertSame('unknown', $this->finding($inspection, 'database_connection')->status->value);
            $this->assertStringNotContainsString('database-password-not-in-output', $this->encoded($inspection));
            $this->assertStringNotContainsString('nested-secret-not-in-output', $this->encoded($inspection));
            $this->assertStringNotContainsString('reports.primary', $this->encoded($inspection));
        } finally {
            config()->set('database.default', 'sqlite');
        }
    }

    #[Test]
    public function invalid_and_missing_values_are_classified_without_being_copied(): void
    {
        $this->installSecrets();
        config()->set('app.cipher', 'not-a-cipher');
        config()->set('database.connections.mysql.driver', 'odbc');
        config()->set('logging.channels.stderr.level', 'verbose');
        config()->set('session.secure', 'true');

        $inspection = $this->inspect();
        $json = $this->encoded($inspection);

        $this->assertSame('invalid', $this->setting($inspection, 'app.cipher')->state->value);
        $this->assertNull($this->setting($inspection, 'app.cipher')->value);
        $this->assertSame('fail', $this->finding($inspection, 'app_cipher')->status->value);
        $this->assertSame('medium', $this->finding($inspection, 'app_cipher')->severity->value);

        $this->assertSame('registered', $this->setting($inspection, 'database.connection')->value);
        $this->assertSame('invalid', $this->setting($inspection, 'database.driver')->state->value);
        $this->assertNull($this->setting($inspection, 'database.driver')->value);
        $this->assertSame('fail', $this->finding($inspection, 'database_connection')->status->value);
        $this->assertStringContainsString('not recognised', $this->finding($inspection, 'database_connection')->description);

        $this->assertSame('invalid', $this->setting($inspection, 'logging.level')->state->value);
        $this->assertSame('fail', $this->finding($inspection, 'logging_channel')->status->value);
        $this->assertSame('invalid', $this->setting($inspection, 'session.secure')->state->value);

        foreach (['not-a-cipher', 'odbc', 'verbose'] as $rejected) {
            $this->assertStringNotContainsString($rejected, $json);
        }
    }

    #[Test]
    public function a_production_https_application_with_a_disabled_secure_cookie_fails(): void
    {
        $this->installSecrets();
        $this->application()->detectEnvironment(static fn (): string => 'production');
        config()->set('app.url', 'https://user:url-secret-not-in-output@config-host-not-in-output.example');
        config()->set('session.secure', false);

        $inspection = $this->inspect();
        $finding = $this->finding($inspection, 'session_secure_cookie');

        $this->assertSame('fail', $finding->status->value);
        $this->assertSame('high', $finding->severity->value);
        $this->assertSame('https', $finding->evidence[1]->observation->value);
        $this->assertSame('disabled', $finding->evidence[2]->observation->value);
        $this->assertSecretsAbsent($this->encoded($inspection));
        $this->assertStringNotContainsString('config-host-not-in-output.example', $finding->description);
    }

    #[Test]
    public function filters_pagination_and_ordering_stay_inside_the_allowlist(): void
    {
        $this->installSecrets();

        $page = $this->inspect(['limit' => 2]);
        $this->assertSame(['app.cipher', 'app.maintenance_driver'], $this->ids($page));
        $this->assertSame(38, $page->pagination->total);
        $this->assertSame(19, $page->pagination->pages);
        $this->assertSame(2, $page->pagination->count);
        $this->assertCount(10, $page->findings);

        $second = $this->inspect(['page' => 2, 'limit' => 2]);
        $this->assertSame(['app.key', 'database.default'], $this->ids($second));

        $database = $this->inspect(['category' => 'Database']);
        $this->assertSame([
            'database.default',
            'database.connection',
            'database.driver',
            'database.password',
            'database.url',
        ], $this->ids($database));
        $this->assertSame('pass', $this->finding($database, 'app_cipher')->status->value);

        $one = $this->inspect(['id' => 'APP.KEY']);
        $this->assertSame(['app.key'], $this->ids($one));
        $this->assertNull($one->settings[0]->value);

        $past = $this->inspect(['page' => 3, 'limit' => 50]);
        $this->assertSame([], $past->settings);
        $this->assertSame(38, $past->pagination->total);
        $this->assertSame(0, $past->pagination->count);
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    #[Test]
    #[DataProvider('invalidFilters')]
    public function invalid_filters_are_rejected_without_the_rejected_value(array $parameters, string $message): void
    {
        $this->expectException(InvalidConfigurationQueryException::class);
        $this->expectExceptionMessage($message);

        try {
            $this->inspector()->inspect($parameters);
        } catch (InvalidConfigurationQueryException $exception) {
            $encoded = (string) json_encode($parameters);
            $this->assertStringNotContainsString($encoded, $exception->getMessage());

            throw $exception;
        }
    }

    /**
     * @return array<string, array{0: array<string, scalar|null>, 1: string}>
     */
    public static function invalidFilters(): array
    {
        return [
            'services' => [['category' => 'services'], 'The category filter must be one approved category: application, database, cache, queue, session, mail, filesystem or logging.'],
            'filesystems' => [['category' => 'filesystems'], 'The category filter must be one approved category: application, database, cache, queue, session, mail, filesystem or logging.'],
            'nested' => [['id' => 'database.connections.mysql.password'], 'The id filter must be an approved configuration setting.'],
            'empty' => [['id' => '   '], 'The id filter must not be empty.'],
            'long' => [['id' => str_repeat('a', 65)], 'The id filter is too long.'],
            'limit' => [['limit' => 101], 'The limit filter must be an integer from 1 to 100.'],
            'page type' => [['page' => '2'], 'The page filter has an unsupported type.'],
            'unknown' => [['path' => 'database.default'], 'Unknown configuration filter. Allowed filters are category, id, page and limit.'],
            'mismatch' => [['category' => 'cache', 'id' => 'database.default'], 'The id filter is not in the selected category.'],
        ];
    }

    #[Test]
    public function a_wildcard_identifier_is_rejected(): void
    {
        $this->expectException(InvalidConfigurationQueryException::class);
        $this->expectExceptionMessage('The id filter must be an approved configuration setting.');

        $this->inspector()->inspect(['id' => 'database.*']);
    }

    #[Test]
    public function a_non_scalar_filter_is_rejected_by_name(): void
    {
        $this->expectException(InvalidConfigurationQueryException::class);
        $this->expectExceptionMessage('The id filter has an unsupported type.');

        ConfigurationQuery::from(['id' => ['database.default']]);
    }

    #[Test]
    public function inspection_does_not_connect_mutate_or_log_secrets(): void
    {
        $this->installSecrets();
        $logged = '';
        Log::listen(function (MessageLogged $message) use (&$logged): void {
            $logged .= $message->message . ' ' . json_encode($message->context);
        });
        Http::fake();
        Mail::fake();
        Queue::fake();

        $password = config('database.connections.mysql.password');
        $key = config('app.key');

        $this->inspect();

        $this->assertSame($password, config('database.connections.mysql.password'));
        $this->assertSame($key, config('app.key'));
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSecretsAbsent($logged);
    }

    #[Test]
    public function it_is_registered_under_the_config_capability(): void
    {
        $registry = $this->application()->make(InspectorRegistry::class);

        $this->assertTrue($registry->enabled(ConfigurationInspector::NAME));
        $this->assertInstanceOf(ConfigurationInspector::class, $registry->get(ConfigurationInspector::NAME));
        $this->assertSame('config:read', (string) $this->inspector()->capability());
    }

    #[Test]
    public function it_runs_through_beacon_only_when_the_config_capability_is_allowed(): void
    {
        $this->installSecrets();
        $beacon = $this->application()->make(Beacon::class);

        try {
            $beacon->inspect(Principal::local(), ConfigurationInspector::NAME);
            $this->fail('The config capability was allowed by default.');
        } catch (AuthorizationException) {
        }

        config()->set('beacon.security.allowed_capabilities', ['config']);

        $payload = $beacon->inspect(Principal::local(), ConfigurationInspector::NAME);
        $json = (string) $payload->toJson();

        $this->assertSame($this->inspect()->toArray(), $payload->toArray());
        $this->assertSecretsAbsent($json);
    }

    #[Test]
    public function it_can_be_disabled(): void
    {
        config()->set('beacon.inspectors.config.enabled', false);
        config()->set('beacon.security.allowed_capabilities', ['config']);

        $this->expectException(InspectorNotFoundException::class);

        $this->application()->make(Beacon::class)->inspect(Principal::local(), ConfigurationInspector::NAME);
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function inspect(array $parameters = []): ConfigurationInspection
    {
        $inspection = $this->inspector()->inspect($parameters);
        $this->assertInstanceOf(ConfigurationInspection::class, $inspection);

        return $inspection;
    }

    private function inspector(): ConfigurationInspector
    {
        return $this->application()->make(ConfigurationInspector::class);
    }

    private function setting(ConfigurationInspection $inspection, string $id): ConfigurationSetting
    {
        foreach ($inspection->settings as $setting) {
            if ($setting->id === $id) {
                return $setting;
            }
        }

        $this->fail('Missing setting [' . $id . '].');
    }

    private function finding(ConfigurationInspection $inspection, string $id): Finding
    {
        foreach ($inspection->findings as $finding) {
            if ($finding->id === $id) {
                return $finding;
            }
        }

        $this->fail('Missing finding [' . $id . '].');
    }

    /**
     * @return list<string>
     */
    private function ids(ConfigurationInspection $inspection): array
    {
        return array_map(static fn (ConfigurationSetting $setting): string => $setting->id, $inspection->settings);
    }

    private function encoded(ConfigurationInspection $inspection): string
    {
        return (string) json_encode($inspection->toArray());
    }

    private function assertSecretsAbsent(string $haystack): void
    {
        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $haystack);
        }
    }

    private function installSecrets(): void
    {
        config()->set('app.key', 'application-key-not-in-output');
        config()->set('app.cipher', 'AES-256-CBC');
        config()->set('app.maintenance.driver', 'file');
        config()->set('app.url', 'https://user:url-secret-not-in-output@config-host-not-in-output.example/app');

        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => 'db-host-not-in-output.example',
            'port' => '3306',
            'database' => 'secret-database-not-in-output',
            'username' => 'secret-user-not-in-output',
            'password' => 'database-password-not-in-output',
            'unix_socket' => '/tmp/mysql-socket-not-in-output.sock',
            'url' => 'mysql://beacon:connection-secret-not-in-output@db.internal/app',
        ]);
        config()->set('database.connections.sqlite.database', '/var/www/sqlite-path-not-in-output.sqlite');
        config()->set('database.redis.default.password', 'redis-password-not-in-output');

        config()->set('cache.default', 'redis');
        config()->set('cache.prefix', 'cache-prefix-not-in-output');
        config()->set('cache.stores.redis', [
            'driver' => 'redis',
            'connection' => 'cache',
            'password' => 'redis-password-not-in-output',
        ]);
        config()->set('cache.stores.memcached.sasl', [null, 'memcached-password-not-in-output']);

        config()->set('queue.default', 'sqs');
        config()->set('queue.connections.sqs', [
            'driver' => 'sqs',
            'key' => 'queue-key-not-in-output',
            'secret' => 'sqs-secret-not-in-output',
            'queue' => 'https://sqs.example/queue-url-not-in-output',
            'prefix' => 'arn:aws:sqs:queue-arn-not-in-output',
        ]);

        config()->set('session.driver', 'redis');
        config()->set('session.secure', true);
        config()->set('session.http_only', true);
        config()->set('session.same_site', 'lax');
        config()->set('session.encrypt', false);
        config()->set('session.cookie', 'laravel_session');
        config()->set('session.domain', 'session-domain-not-in-output.example');
        config()->set('session.path', '/session-path-not-in-output');

        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp', [
            'transport' => 'smtp',
            'scheme' => 'smtp',
            'host' => 'smtp-host-not-in-output.example',
            'username' => 'mail-username-not-in-output',
            'password' => 'mail-password-not-in-output',
        ]);

        config()->set('filesystems.default', 's3');
        config()->set('filesystems.disks.s3', [
            'driver' => 's3',
            'key' => 'disk-key-not-in-output',
            'secret' => "-----BEGIN PRIVATE KEY-----\nfake-key-not-in-output\n-----END PRIVATE KEY-----",
            'region' => 'disk-region-not-in-output',
            'bucket' => 'disk-bucket-not-in-output',
            'url' => 'https://disk-endpoint-not-in-output.example/disk',
            'endpoint' => 'https://disk-endpoint-not-in-output.example',
            'root' => '/var/www/disk-root-not-in-output',
            'visibility' => 'private',
        ]);
        config()->set('filesystems.disks.local.root', '/var/www/disk-root-not-in-output');

        config()->set('logging.default', 'stderr');
        config()->set('logging.channels.stderr', [
            'driver' => 'monolog',
            'handler' => 'Monolog\\Handler\\StreamHandler',
            'level' => 'debug',
            'with' => ['stream' => 'php://stderr'],
        ]);
        config()->set('logging.channels.slack.url', 'https://hooks.example.test/slack-webhook-not-in-output');

        config()->set('services.stripe.secret', 'stripe-secret-not-in-output');
    }
}
