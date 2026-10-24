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
 * Confirms whether debug mode is enabled in production.
 *
 * A non-boolean app.debug is unknown: Laravel treats many non-empty strings
 * as enabled, so guessing false would hide the misconfiguration.
 */
final class DebugEnabledInProduction extends EnvironmentCheck
{
    public const ID = 'debug_enabled_in_production';

    private const TITLE = 'Debug mode in production';

    public function evaluate(EnvironmentFacts $facts): Finding
    {
        $observation = $this->observation($facts->setting('app.debug'));
        $evidence = [
            $this->environmentEvidence($facts),
            new FindingEvidence('app.debug', $observation),
        ];

        if ($pass = $this->passOutsideProduction($facts, self::ID, self::TITLE, $evidence)) {
            return $pass;
        }

        if ($observation === Observation::Enabled) {
            return $this->finding(
                self::ID,
                FindingStatus::Fail,
                Severity::High,
                self::TITLE,
                'Debug mode is enabled in production.',
                $evidence,
                'Set APP_DEBUG to false in production.',
            );
        }

        if ($observation === Observation::Disabled) {
            return $this->finding(
                self::ID,
                FindingStatus::Pass,
                Severity::None,
                self::TITLE,
                'Debug mode is disabled in production.',
                $evidence,
                null,
            );
        }

        return $this->finding(
            self::ID,
            FindingStatus::Unknown,
            Severity::None,
            self::TITLE,
            'Debug mode could not be evaluated because app.debug is missing or not a boolean.',
            $evidence,
            'Set app.debug to true or false. A non-boolean value is not reported as off.',
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

        if ($setting->state !== ConfigurationState::Present || ! is_bool($setting->value)) {
            return Observation::Invalid;
        }

        return $setting->value ? Observation::Enabled : Observation::Disabled;
    }
}
