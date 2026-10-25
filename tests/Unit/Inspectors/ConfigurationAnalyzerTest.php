<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Inspectors;

use Beacon\Data\ConfigurationCatalog;
use Beacon\Data\ConfigurationCategory;
use Beacon\Data\ConfigurationSetting;
use Beacon\Data\ConfigurationState;
use Beacon\Data\Finding;
use Beacon\Inspectors\Configuration\Checks\AppCipher;
use Beacon\Inspectors\Configuration\Checks\AppMaintenanceDriver;
use Beacon\Inspectors\Configuration\Checks\DatabaseConnection;
use Beacon\Inspectors\Configuration\Checks\LoggingChannel;
use Beacon\Inspectors\Configuration\Checks\SessionSecureCookie;
use Beacon\Inspectors\Configuration\ConfigurationAnalysis;
use Beacon\Inspectors\Configuration\ConfigurationAnalyzer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfigurationAnalyzerTest extends TestCase
{
    #[Test]
    public function a_healthy_testing_catalog_passes_every_check_in_order(): void
    {
        $analysis = $this->analyze($this->catalog());

        $this->assertSame([
            AppCipher::ID,
            AppMaintenanceDriver::ID,
            DatabaseConnection::ID,
            'cache_store',
            'queue_connection',
            'mail_mailer',
            'filesystem_disk',
            LoggingChannel::ID,
            'session_driver',
            SessionSecureCookie::ID,
        ], array_map(static fn (Finding $finding): string => $finding->id, $analysis->findings));

        foreach ($analysis->findings as $finding) {
            $this->assertSame('pass', $finding->status->value, $finding->id);
            $this->assertSame('none', $finding->severity->value, $finding->id);
            $this->assertNull($finding->remediation, $finding->id);
        }
    }

    #[Test]
    public function an_unregistered_database_connection_fails_without_repeating_the_name(): void
    {
        $analysis = $this->analyze($this->catalog([
            'database.default' => [ConfigurationState::Present, 'missing_connection'],
            'database.connection' => [ConfigurationState::Invalid, null],
            'database.driver' => [ConfigurationState::Missing, null],
        ]));
        $finding = $this->finding($analysis, DatabaseConnection::ID);
        $json = (string) json_encode($finding->toArray());

        $this->assertSame('fail', $finding->status->value);
        $this->assertSame('medium', $finding->severity->value);
        $this->assertStringContainsString('not registered', $finding->description);
        $this->assertStringNotContainsString('missing_connection', $json);
    }

    #[Test]
    public function an_unrecognised_driver_fails_without_the_driver_value(): void
    {
        $finding = $this->finding($this->analyze($this->catalog([
            'cache.driver' => [ConfigurationState::Invalid, null],
        ])), 'cache_store');

        $this->assertSame('fail', $finding->status->value);
        $this->assertStringContainsString('not recognised', $finding->description);
        $this->assertStringNotContainsString('odbc', (string) json_encode($finding->toArray()));
    }

    #[Test]
    public function a_withheld_default_name_is_unknown_when_its_definition_was_not_read(): void
    {
        $finding = $this->finding($this->analyze($this->catalog([
            'queue.default' => [ConfigurationState::Sensitive, null],
            'queue.connection' => [ConfigurationState::Invalid, null],
            'queue.driver' => [ConfigurationState::Invalid, null],
        ])), 'queue_connection');

        $this->assertSame('unknown', $finding->status->value);
        $this->assertSame('none', $finding->severity->value);
        $this->assertNotNull($finding->remediation);
    }

    #[Test]
    public function a_withheld_name_can_still_pass_when_the_definition_was_read(): void
    {
        $finding = $this->finding($this->analyze($this->catalog([
            'mail.default' => [ConfigurationState::Sensitive, null],
        ])), 'mail_mailer');

        $this->assertSame('pass', $finding->status->value);
        $this->assertStringContainsString('withheld', $finding->description);
    }

    #[Test]
    public function an_invalid_logging_level_fails_and_a_missing_level_does_not(): void
    {
        $missing = $this->finding($this->analyze($this->catalog()), LoggingChannel::ID);
        $invalid = $this->finding($this->analyze($this->catalog([
            'logging.level' => [ConfigurationState::Invalid, null],
        ])), LoggingChannel::ID);

        $this->assertSame('pass', $missing->status->value);
        $this->assertSame('fail', $invalid->status->value);
        $this->assertSame('medium', $invalid->severity->value);
        $this->assertStringContainsString('level', $invalid->description);
        $this->assertStringNotContainsString('verbose', (string) json_encode($invalid->toArray()));
    }

    #[Test]
    public function cipher_and_maintenance_failures_do_not_repeat_the_rejected_value(): void
    {
        $cipher = $this->finding($this->analyze($this->catalog([
            'app.cipher' => [ConfigurationState::Invalid, null],
        ])), AppCipher::ID);
        $maintenance = $this->finding($this->analyze($this->catalog([
            'app.maintenance_driver' => [ConfigurationState::Invalid, null],
        ])), AppMaintenanceDriver::ID);
        $unset = $this->finding($this->analyze($this->catalog([
            'app.maintenance_driver' => [ConfigurationState::Missing, null],
        ])), AppMaintenanceDriver::ID);

        $this->assertSame('fail', $cipher->status->value);
        $this->assertSame('medium', $cipher->severity->value);
        $this->assertSame('fail', $maintenance->status->value);
        $this->assertSame('low', $maintenance->severity->value);
        $this->assertSame('pass', $unset->status->value);
        $this->assertStringNotContainsString('rot13', (string) json_encode($cipher->toArray()));
    }

    #[Test]
    public function the_secure_cookie_check_requires_production_and_https(): void
    {
        $outside = $this->finding($this->analyze($this->catalog([
            'session.secure' => [ConfigurationState::Present, false],
        ])), SessionSecureCookie::ID);
        $disabled = $this->finding($this->analyze($this->catalog([
            'session.secure' => [ConfigurationState::Present, false],
        ], 'production', 'https')), SessionSecureCookie::ID);
        $missing = $this->finding($this->analyze($this->catalog([
            'session.secure' => [ConfigurationState::Missing, null],
        ], 'production', 'https')), SessionSecureCookie::ID);
        $http = $this->finding($this->analyze($this->catalog([
            'session.secure' => [ConfigurationState::Present, false],
        ], 'production', 'http')), SessionSecureCookie::ID);

        $this->assertSame('pass', $outside->status->value);
        $this->assertSame('fail', $disabled->status->value);
        $this->assertSame('high', $disabled->severity->value);
        $this->assertSame('risk', $missing->status->value);
        $this->assertSame('medium', $missing->severity->value);
        $this->assertSame('unknown', $http->status->value);
        $this->assertSame('none', $http->severity->value);
        $this->assertStringNotContainsString('https://', (string) json_encode($disabled->toArray()));
    }

    /**
     * @param array<string, array{0: ConfigurationState, 1: bool|string|null}> $overrides
     */
    private function catalog(array $overrides = [], string $environment = 'testing', ?string $urlScheme = 'https'): ConfigurationCatalog
    {
        $settings = [];

        foreach (ConfigurationCatalog::IDS as $id) {
            $category = ConfigurationCatalog::categoryFor($id);
            $this->assertInstanceOf(ConfigurationCategory::class, $category);
            [$state, $value] = $overrides[$id] ?? $this->defaults()[$id];
            $settings[] = new ConfigurationSetting($category, $id, $state, $value);
        }

        return new ConfigurationCatalog($environment, $urlScheme, $settings);
    }

    private function analyze(ConfigurationCatalog $catalog): ConfigurationAnalysis
    {
        $analysis = (new ConfigurationAnalyzer())->analyze($catalog);
        $this->assertInstanceOf(ConfigurationAnalysis::class, $analysis);

        return $analysis;
    }

    private function finding(ConfigurationAnalysis $analysis, string $id): Finding
    {
        foreach ($analysis->findings as $finding) {
            if ($finding->id === $id) {
                return $finding;
            }
        }

        $this->fail('Missing finding [' . $id . '].');
    }

    /**
     * @return array<string, array{0: ConfigurationState, 1: bool|string|null}>
     */
    private function defaults(): array
    {
        $present = static fn (string $value): array => [ConfigurationState::Present, $value];
        $sensitive = [ConfigurationState::Sensitive, null];
        $missing = [ConfigurationState::Missing, null];

        return [
            'app.cipher' => $present('AES-256-CBC'),
            'app.maintenance_driver' => $present('file'),
            'app.key' => $sensitive,
            'database.default' => $present('mysql'),
            'database.connection' => $present('registered'),
            'database.driver' => $present('mysql'),
            'database.password' => $sensitive,
            'database.url' => $missing,
            'cache.default' => $present('array'),
            'cache.store' => $present('registered'),
            'cache.driver' => $present('array'),
            'queue.default' => $present('sync'),
            'queue.connection' => $present('registered'),
            'queue.driver' => $present('sync'),
            'queue.name' => $present('default'),
            'session.driver' => $present('array'),
            'session.secure' => [ConfigurationState::Present, true],
            'session.http_only' => [ConfigurationState::Present, true],
            'session.same_site' => $present('lax'),
            'session.encrypt' => [ConfigurationState::Present, false],
            'session.cookie' => $present('laravel_session'),
            'mail.default' => $present('smtp'),
            'mail.mailer' => $present('registered'),
            'mail.transport' => $present('smtp'),
            'mail.scheme' => $present('tls'),
            'mail.username' => $sensitive,
            'mail.password' => $sensitive,
            'filesystem.default' => $present('local'),
            'filesystem.disk' => $present('registered'),
            'filesystem.driver' => $present('local'),
            'filesystem.visibility' => $present('private'),
            'filesystem.credentials' => $missing,
            'logging.default' => $present('stack'),
            'logging.channel' => $present('registered'),
            'logging.driver' => $present('stack'),
            'logging.level' => $missing,
            'logging.handler' => $missing,
            'logging.webhook' => $missing,
        ];
    }
}
