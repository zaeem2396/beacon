<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Inspectors;

use Beacon\Data\ConfigurationState;
use Beacon\Data\EnvironmentFacts;
use Beacon\Data\EnvironmentInspection;
use Beacon\Data\EnvironmentSetting;
use Beacon\Data\Finding;
use Beacon\Inspectors\Environment\Checks\AppKeyConfigured;
use Beacon\Inspectors\Environment\Checks\AppUrlSchemeInProduction;
use Beacon\Inspectors\Environment\Checks\DebugEnabledInProduction;
use Beacon\Inspectors\Environment\Checks\SessionHttpOnlyInProduction;
use Beacon\Inspectors\Environment\Checks\SessionSameSiteInProduction;
use Beacon\Inspectors\Environment\Checks\SessionSecureCookieInProduction;
use Beacon\Inspectors\Environment\EnvironmentAnalyzer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EnvironmentAnalyzerTest extends TestCase
{
    private const IDS = [
        DebugEnabledInProduction::ID,
        AppKeyConfigured::ID,
        AppUrlSchemeInProduction::ID,
        SessionSecureCookieInProduction::ID,
        SessionHttpOnlyInProduction::ID,
        SessionSameSiteInProduction::ID,
    ];

    #[Test]
    public function checks_run_in_a_stable_order(): void
    {
        $inspection = $this->analyze($this->facts('production'));

        $this->assertSame(self::IDS, array_map(static fn (Finding $finding): string => $finding->id, $inspection->findings));
    }

    #[Test]
    public function a_hardened_production_configuration_passes_every_check(): void
    {
        foreach ($this->analyze($this->facts('production'))->findings as $finding) {
            $this->assertSame('pass', $finding->status->value, $finding->id);
            $this->assertSame('none', $finding->severity->value, $finding->id);
            $this->assertNull($finding->remediation, $finding->id);
        }
    }

    /**
     * @param array<string, EnvironmentSetting|null> $settings
     */
    #[Test]
    #[DataProvider('cases')]
    public function it_classifies_each_check(string $environment, array $settings, string $id, string $status, string $severity): void
    {
        $finding = $this->finding($this->analyze($this->facts($environment, $settings)), $id);

        $this->assertSame($status, $finding->status->value);
        $this->assertSame($severity, $finding->severity->value);
        $this->assertSame($status === 'pass' ? null : true, $finding->remediation === null ? null : true);

        $encoded = (string) json_encode($finding->toArray());

        $this->assertStringNotContainsString('plain-text', $encoded);
        $this->assertDoesNotMatchRegularExpression('/sk_|AKIA|ghp_|github_pat_/', $encoded);
    }

    #[Test]
    public function evidence_uses_classifications_rather_than_configuration_values(): void
    {
        $finding = $this->finding(
            $this->analyze($this->facts('production', [
                'app.url' => new EnvironmentSetting('app.url', ConfigurationState::Present, 'http'),
            ])),
            AppUrlSchemeInProduction::ID,
        );

        $this->assertSame([
            ['name' => 'environment', 'observation' => 'production'],
            ['name' => 'app.url', 'observation' => 'http'],
        ], $finding->evidence === [] ? [] : array_map(
            static fn ($item) => ['name' => $item->name, 'observation' => $item->observation->value],
            $finding->evidence,
        ));
    }

    #[Test]
    public function a_missing_check_input_is_unknown_instead_of_a_failure(): void
    {
        $facts = $this->facts('production');
        $settings = array_values(array_filter(
            $facts->settings,
            static fn (EnvironmentSetting $setting): bool => $setting->name !== 'app.key',
        ));

        $finding = $this->finding($this->analyze(new EnvironmentFacts(
            'production',
            false,
            'UTC',
            'en',
            false,
            false,
            $settings,
        )), AppKeyConfigured::ID);

        $this->assertSame('unknown', $finding->status->value);
        $this->assertSame('none', $finding->severity->value);
    }

    /**
     * @return iterable<string, array{string, array<string, EnvironmentSetting|null>, string, string, string}>
     */
    public static function cases(): iterable
    {
        yield 'debug enabled in production' => ['production', [
            'app.debug' => new EnvironmentSetting('app.debug', ConfigurationState::Present, true),
        ], DebugEnabledInProduction::ID, 'fail', 'high'];

        yield 'debug disabled in production' => ['production', [], DebugEnabledInProduction::ID, 'pass', 'none'];

        yield 'debug enabled outside production' => ['local', [
            'app.debug' => new EnvironmentSetting('app.debug', ConfigurationState::Present, true),
        ], DebugEnabledInProduction::ID, 'pass', 'none'];

        yield 'debug not a boolean in production' => ['production', [
            'app.debug' => new EnvironmentSetting('app.debug', ConfigurationState::Invalid, null),
        ], DebugEnabledInProduction::ID, 'unknown', 'none'];

        yield 'debug missing in production' => ['production', [
            'app.debug' => new EnvironmentSetting('app.debug', ConfigurationState::Missing, null),
        ], DebugEnabledInProduction::ID, 'unknown', 'none'];

        yield 'application key missing' => ['local', [
            'app.key' => new EnvironmentSetting('app.key', ConfigurationState::Missing, null),
        ], AppKeyConfigured::ID, 'fail', 'high'];

        yield 'application key present' => ['production', [], AppKeyConfigured::ID, 'pass', 'none'];

        yield 'http url in production' => ['production', [
            'app.url' => new EnvironmentSetting('app.url', ConfigurationState::Present, 'http'),
        ], AppUrlSchemeInProduction::ID, 'fail', 'medium'];

        yield 'https url in production' => ['production', [], AppUrlSchemeInProduction::ID, 'pass', 'none'];

        yield 'http url outside production' => ['local', [
            'app.url' => new EnvironmentSetting('app.url', ConfigurationState::Present, 'http'),
        ], AppUrlSchemeInProduction::ID, 'pass', 'none'];

        yield 'missing url in production' => ['production', [
            'app.url' => new EnvironmentSetting('app.url', ConfigurationState::Missing, null),
        ], AppUrlSchemeInProduction::ID, 'unknown', 'none'];

        yield 'invalid url in production' => ['production', [
            'app.url' => new EnvironmentSetting('app.url', ConfigurationState::Invalid, null),
        ], AppUrlSchemeInProduction::ID, 'unknown', 'none'];

        yield 'secure cookie disabled in production' => ['production', [
            'session.secure' => new EnvironmentSetting('session.secure', ConfigurationState::Present, false),
        ], SessionSecureCookieInProduction::ID, 'fail', 'high'];

        yield 'secure cookie enabled in production' => ['production', [], SessionSecureCookieInProduction::ID, 'pass', 'none'];

        yield 'secure cookie unset in production' => ['production', [
            'session.secure' => new EnvironmentSetting('session.secure', ConfigurationState::Missing, null),
        ], SessionSecureCookieInProduction::ID, 'risk', 'medium'];

        yield 'secure cookie unset outside production' => ['testing', [
            'session.secure' => new EnvironmentSetting('session.secure', ConfigurationState::Missing, null),
        ], SessionSecureCookieInProduction::ID, 'pass', 'none'];

        yield 'secure cookie invalid in production' => ['production', [
            'session.secure' => new EnvironmentSetting('session.secure', ConfigurationState::Invalid, null),
        ], SessionSecureCookieInProduction::ID, 'unknown', 'none'];

        yield 'http only disabled in production' => ['production', [
            'session.http_only' => new EnvironmentSetting('session.http_only', ConfigurationState::Present, false),
        ], SessionHttpOnlyInProduction::ID, 'fail', 'high'];

        yield 'http only missing in production' => ['production', [
            'session.http_only' => new EnvironmentSetting('session.http_only', ConfigurationState::Missing, null),
        ], SessionHttpOnlyInProduction::ID, 'unknown', 'none'];

        yield 'same site none in production' => ['production', [
            'session.same_site' => new EnvironmentSetting('session.same_site', ConfigurationState::Present, 'none'),
        ], SessionSameSiteInProduction::ID, 'risk', 'low'];

        yield 'same site strict in production' => ['production', [
            'session.same_site' => new EnvironmentSetting('session.same_site', ConfigurationState::Present, 'strict'),
        ], SessionSameSiteInProduction::ID, 'pass', 'none'];

        yield 'same site invalid in production' => ['production', [
            'session.same_site' => new EnvironmentSetting('session.same_site', ConfigurationState::Invalid, null),
        ], SessionSameSiteInProduction::ID, 'unknown', 'none'];
    }

    /**
     * @param array<string, EnvironmentSetting|null> $overrides
     */
    private function facts(string $environment, array $overrides = []): EnvironmentFacts
    {
        $settings = [
            'app.debug' => new EnvironmentSetting('app.debug', ConfigurationState::Present, false),
            'app.timezone' => new EnvironmentSetting('app.timezone', ConfigurationState::Present, 'UTC'),
            'app.locale' => new EnvironmentSetting('app.locale', ConfigurationState::Present, 'en'),
            'app.url' => new EnvironmentSetting('app.url', ConfigurationState::Present, 'https'),
            'app.key' => new EnvironmentSetting('app.key', ConfigurationState::Sensitive, null),
            'session.secure' => new EnvironmentSetting('session.secure', ConfigurationState::Present, true),
            'session.http_only' => new EnvironmentSetting('session.http_only', ConfigurationState::Present, true),
            'session.same_site' => new EnvironmentSetting('session.same_site', ConfigurationState::Present, 'lax'),
        ];

        foreach ($overrides as $name => $setting) {
            if ($setting === null) {
                unset($settings[$name]);

                continue;
            }

            $settings[$name] = $setting;
        }

        $debug = $settings['app.debug'] ?? null;

        return new EnvironmentFacts(
            $environment,
            $debug instanceof EnvironmentSetting && is_bool($debug->value) ? $debug->value : null,
            'UTC',
            'en',
            false,
            false,
            array_values($settings),
        );
    }

    private function analyze(EnvironmentFacts $facts): EnvironmentInspection
    {
        $inspection = (new EnvironmentAnalyzer())->analyze($facts);
        $this->assertInstanceOf(EnvironmentInspection::class, $inspection);

        return $inspection;
    }

    private function finding(EnvironmentInspection $inspection, string $id): Finding
    {
        foreach ($inspection->findings as $finding) {
            if ($finding->id === $id) {
                return $finding;
            }
        }

        $this->fail("Finding [{$id}] was not produced.");
    }
}
