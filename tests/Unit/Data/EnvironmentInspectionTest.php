<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Data;

use Beacon\Core\Payload;
use Beacon\Data\ConfigurationState;
use Beacon\Data\EnvironmentFacts;
use Beacon\Data\EnvironmentInspection;
use Beacon\Data\EnvironmentSetting;
use Beacon\Data\Finding;
use Beacon\Data\FindingEvidence;
use Beacon\Data\FindingStatus;
use Beacon\Data\Observation;
use Beacon\Data\Severity;
use Beacon\Security\KeyPatternRedactor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EnvironmentInspectionTest extends TestCase
{
    #[Test]
    public function it_serializes_metadata_and_findings_without_dropping_nulls(): void
    {
        $inspection = $this->inspection();
        $data = $inspection->toArray();

        $this->assertSame([
            'schema_version',
            'metadata',
            'findings',
        ], array_keys($data));
        $this->assertSame(1, $inspection->schemaVersion);
        $this->assertSame([
            'environment',
            'debug',
            'timezone',
            'locale',
            'configuration_cached',
            'routes_cached',
            'settings',
        ], array_keys($inspection->metadata->toArray()));
        $this->assertNull($inspection->metadata->debug);
        $this->assertNull($inspection->metadata->timezone);
        $this->assertNull($inspection->metadata->configurationCached);
        $this->assertSame('sensitive', $inspection->metadata->settings[0]->state->value);
        $this->assertNull($inspection->metadata->settings[0]->value);
        $this->assertSame('unknown', $inspection->findings[0]->status->value);
        $this->assertIsString($inspection->findings[0]->remediation);
    }

    #[Test]
    public function a_credential_in_the_environment_name_is_redacted_at_the_payload_boundary(): void
    {
        $facts = new EnvironmentFacts(
            'mysql://beacon:cli-secret@db.internal/app',
            null,
            null,
            null,
            null,
            null,
            [new EnvironmentSetting('app.key', ConfigurationState::Sensitive, null)],
        );

        $json = Payload::fromData(new EnvironmentInspection($facts, []), new KeyPatternRedactor())->toJson();

        $this->assertStringNotContainsString('cli-secret', $json);
        $this->assertStringNotContainsString('db.internal', $json);
    }

    #[Test]
    public function a_sensitive_setting_cannot_carry_its_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EnvironmentSetting('app.key', ConfigurationState::Sensitive, 'plain-text-application-key');
    }

    private function inspection(): EnvironmentInspection
    {
        return new EnvironmentInspection(
            new EnvironmentFacts(
                'production',
                null,
                null,
                'en',
                null,
                false,
                [new EnvironmentSetting('app.key', ConfigurationState::Sensitive, null)],
            ),
            [new Finding(
                'app_url_scheme_in_production',
                FindingStatus::Unknown,
                Severity::None,
                'Application URL scheme in production',
                'The application URL scheme could not be evaluated.',
                [new FindingEvidence('app.url', Observation::Missing)],
                'Set APP_URL to an absolute https URL so its scheme can be checked.',
            )],
        );
    }
}
