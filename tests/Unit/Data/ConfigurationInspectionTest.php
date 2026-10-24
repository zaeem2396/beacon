<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Data;

use Beacon\Data\ConfigurationCatalog;
use Beacon\Data\ConfigurationCategory;
use Beacon\Data\ConfigurationInspection;
use Beacon\Data\ConfigurationPage;
use Beacon\Data\ConfigurationSetting;
use Beacon\Data\ConfigurationState;
use Beacon\Data\Finding;
use Beacon\Data\FindingEvidence;
use Beacon\Data\FindingStatus;
use Beacon\Data\Observation;
use Beacon\Data\Severity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfigurationInspectionTest extends TestCase
{
    #[Test]
    public function it_serializes_settings_and_findings_without_dropping_nulls(): void
    {
        $inspection = new ConfigurationInspection(
            new ConfigurationPage(1, 50, 2, 1, 2),
            [
                new ConfigurationSetting(ConfigurationCategory::Application, 'app.cipher', ConfigurationState::Present, 'AES-256-CBC'),
                new ConfigurationSetting(ConfigurationCategory::Application, 'app.key', ConfigurationState::Sensitive, null),
            ],
            [$this->finding()],
        );
        $data = $inspection->toArray();

        $this->assertSame(['schema_version', 'pagination', 'settings', 'findings'], array_keys($data));
        $this->assertSame(1, $inspection->schemaVersion);
        $this->assertSame(['page', 'per_page', 'total', 'pages', 'count'], array_keys($inspection->pagination->toArray()));
        $this->assertSame(['category', 'id', 'state', 'value'], array_keys($inspection->settings[0]->toArray()));
        $this->assertSame('application', $inspection->settings[0]->category->value);
        $this->assertSame('present', $inspection->settings[0]->state->value);
        $this->assertSame('AES-256-CBC', $inspection->settings[0]->value);
        $this->assertNull($inspection->settings[1]->value);
        $this->assertSame('sensitive', $inspection->settings[1]->state->value);
        $this->assertSame('unknown', $inspection->findings[0]->status->value);
        $sensitive = $inspection->settings[1]->toArray();
        $this->assertArrayHasKey('value', $sensitive);
        $this->assertNull($sensitive['value']);
    }

    #[Test]
    public function a_session_flag_may_be_a_boolean_and_other_settings_may_not(): void
    {
        $secure = new ConfigurationSetting(ConfigurationCategory::Session, 'session.secure', ConfigurationState::Present, false);

        $this->assertFalse($secure->value);

        $this->expectException(\InvalidArgumentException::class);

        new ConfigurationSetting(ConfigurationCategory::Database, 'database.driver', ConfigurationState::Present, false);
    }

    #[Test]
    public function a_sensitive_setting_cannot_carry_its_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ConfigurationSetting(
            ConfigurationCategory::Database,
            'database.password',
            ConfigurationState::Sensitive,
            'database-password-not-in-output',
        );
    }

    #[Test]
    public function a_present_value_cannot_be_a_secret_a_path_or_a_credential_url(): void
    {
        $rejected = 0;

        foreach (['database-password-not-in-output', '/tmp/mysql-socket-not-in-output.sock', 'mysql://beacon:connection-secret-not-in-output@db.internal/app'] as $value) {
            try {
                new ConfigurationSetting(ConfigurationCategory::Database, 'database.default', ConfigurationState::Present, $value);
                $this->fail('Accepted an unsafe configuration value.');
            } catch (\InvalidArgumentException) {
                $rejected++;
            }
        }

        $this->assertSame(3, $rejected);
    }

    #[Test]
    public function pagination_count_must_match_the_page(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ConfigurationPage(1, 1, 1, 1, 2);
    }

    #[Test]
    public function the_catalog_accepts_only_the_approved_settings_in_order(): void
    {
        $this->assertNull(ConfigurationCatalog::categoryFor('database.connections.mysql.password'));
        $this->assertNull(ConfigurationCatalog::categoryFor('services.stripe.secret'));
        $this->assertSame(ConfigurationCategory::Filesystem, ConfigurationCatalog::categoryFor('filesystem.driver'));
        $this->assertCount(38, ConfigurationCatalog::IDS);

        $this->expectException(\InvalidArgumentException::class);

        new ConfigurationCatalog('testing', 'ftp', []);
    }

    private function finding(): Finding
    {
        return new Finding(
            'database_connection',
            FindingStatus::Unknown,
            Severity::None,
            'Default database connection',
            'The default database connection could not be read safely.',
            [new FindingEvidence('database.default', Observation::Sensitive)],
            'Use a short connection name that does not contain credentials so this check can be evaluated.',
        );
    }
}
