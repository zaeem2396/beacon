<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Environment\Checks;

use Beacon\Data\ConfigurationState;
use Beacon\Data\EnvironmentFacts;
use Beacon\Data\EnvironmentSetting;
use Beacon\Data\Finding;
use Beacon\Data\FindingEvidence;
use Beacon\Data\FindingStatus;
use Beacon\Data\Observation;
use Beacon\Data\Severity;
use Beacon\Inspectors\Environment\EnvironmentCheck;

/**
 * Checks the scheme of the configured application URL in production.
 *
 * The URL itself is not evidence. Only the scheme classification is.
 */
final class AppUrlSchemeInProduction extends EnvironmentCheck
{
    public const ID = 'app_url_scheme_in_production';

    private const TITLE = 'Application URL scheme in production';

    public function evaluate(EnvironmentFacts $facts): Finding
    {
        $observation = $this->observation($facts->setting('app.url'));
        $evidence = [
            $this->environmentEvidence($facts),
            new FindingEvidence('app.url', $observation),
        ];

        if ($pass = $this->passOutsideProduction($facts, self::ID, self::TITLE, $evidence)) {
            return $pass;
        }

        if ($observation === Observation::Https) {
            return $this->finding(
                self::ID,
                FindingStatus::Pass,
                Severity::None,
                self::TITLE,
                'The configured application URL uses https.',
                $evidence,
                null,
            );
        }

        if ($observation === Observation::Http) {
            return $this->finding(
                self::ID,
                FindingStatus::Fail,
                Severity::Medium,
                self::TITLE,
                'The configured application URL uses http in production.',
                $evidence,
                'Set APP_URL to an absolute https URL. The URL itself is not included in this result.',
            );
        }

        return $this->finding(
            self::ID,
            FindingStatus::Unknown,
            Severity::None,
            self::TITLE,
            'The application URL scheme could not be evaluated because app.url is missing or not an absolute http(s) URL.',
            $evidence,
            'Set APP_URL to an absolute https URL so its scheme can be checked.',
        );
    }

    private function observation(?EnvironmentSetting $setting): Observation
    {
        if ($setting === null || $setting->state === ConfigurationState::Invalid) {
            return Observation::Invalid;
        }

        if ($setting->state === ConfigurationState::Missing) {
            return Observation::Missing;
        }

        return match ($setting->value) {
            'https' => Observation::Https,
            'http' => Observation::Http,
            default => Observation::Invalid,
        };
    }
}
