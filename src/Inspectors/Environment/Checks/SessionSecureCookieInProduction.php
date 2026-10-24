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
 * Checks the session cookie Secure flag in production.
 *
 * An explicit false is a confirmed misconfiguration. An unset value is only
 * a potential risk: Laravel does not force the flag when the setting is null.
 */
final class SessionSecureCookieInProduction extends EnvironmentCheck
{
    public const ID = 'session_secure_cookie_in_production';

    private const TITLE = 'Secure session cookie in production';

    public function evaluate(EnvironmentFacts $facts): Finding
    {
        $observation = $this->observation($facts->setting('session.secure'));
        $evidence = [
            $this->environmentEvidence($facts),
            new FindingEvidence('session.secure', $observation),
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
                'The session cookie Secure flag is enabled in production.',
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
                'The session cookie Secure flag is disabled in production.',
                $evidence,
                'Set SESSION_SECURE_COOKIE to true so the session cookie is only sent over HTTPS.',
            );
        }

        if ($observation === Observation::Missing) {
            return $this->finding(
                self::ID,
                FindingStatus::Risk,
                Severity::Medium,
                self::TITLE,
                'The session cookie Secure flag is not set in production, so Laravel will not mark the cookie Secure.',
                $evidence,
                'Set SESSION_SECURE_COOKIE to true in production unless TLS termination is already forcing the Secure flag.',
            );
        }

        return $this->finding(
            self::ID,
            FindingStatus::Unknown,
            Severity::None,
            self::TITLE,
            'The session cookie Secure flag could not be evaluated because session.secure is not a boolean.',
            $evidence,
            'Set SESSION_SECURE_COOKIE to true or false so this check can be evaluated.',
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
