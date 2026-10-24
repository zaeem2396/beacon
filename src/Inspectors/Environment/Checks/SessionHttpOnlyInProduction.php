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
 * Checks that the session cookie is HTTP-only in production.
 *
 * An unset value is unknown rather than assumed safe, even though Laravel's
 * published default is true. This inspection reports effective configuration.
 */
final class SessionHttpOnlyInProduction extends EnvironmentCheck
{
    public const ID = 'session_http_only_in_production';

    private const TITLE = 'HTTP-only session cookie in production';

    public function evaluate(EnvironmentFacts $facts): Finding
    {
        $observation = $this->observation($facts->setting('session.http_only'));
        $evidence = [
            $this->environmentEvidence($facts),
            new FindingEvidence('session.http_only', $observation),
        ];

        if ($pass = $this->passOutsideProduction($facts, self::ID, self::TITLE, $evidence)) {
            return $pass;
        }

        if ($observation === Observation::Enabled) {
            return $this->finding(
                self::ID,
                FindingStatus::Pass,
                Severity::None,
                self::TITLE,
                'The session cookie is HTTP-only in production.',
                $evidence,
                null,
            );
        }

        if ($observation === Observation::Disabled) {
            return $this->finding(
                self::ID,
                FindingStatus::Fail,
                Severity::High,
                self::TITLE,
                'The session cookie is readable by browser scripts in production.',
                $evidence,
                'Set SESSION_HTTP_ONLY to true so browser scripts cannot read the session cookie.',
            );
        }

        return $this->finding(
            self::ID,
            FindingStatus::Unknown,
            Severity::None,
            self::TITLE,
            'The HTTP-only session cookie setting could not be evaluated because session.http_only is missing or not a boolean.',
            $evidence,
            'Set SESSION_HTTP_ONLY to true or false so this check can be evaluated.',
        );
    }

    private function observation(?EnvironmentSetting $setting): Observation
    {
        if ($setting?->state === ConfigurationState::Missing) {
            return Observation::Missing;
        }

        if ($setting === null || $setting->state !== ConfigurationState::Present || ! is_bool($setting->value)) {
            return Observation::Invalid;
        }

        return $setting->value ? Observation::Enabled : Observation::Disabled;
    }
}
