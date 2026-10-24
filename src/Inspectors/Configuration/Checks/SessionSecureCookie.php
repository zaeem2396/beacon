<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Configuration\Checks;

use Beacon\Data\ConfigurationCatalog;
use Beacon\Data\ConfigurationSetting;
use Beacon\Data\ConfigurationState;
use Beacon\Data\Finding;
use Beacon\Data\FindingEvidence;
use Beacon\Data\FindingStatus;
use Beacon\Data\Observation;
use Beacon\Data\Severity;
use Beacon\Inspectors\Configuration\ConfigurationCheck;

/**
 * Compares the session cookie Secure flag with a production HTTPS deployment.
 *
 * The check fails only when the environment is exactly "production" and the
 * application URL scheme is https. Any other deployment context is unknown or
 * a pass, so a local HTTP application is not reported as a production risk.
 * The URL itself is not included.
 */
final class SessionSecureCookie extends ConfigurationCheck
{
    public const ID = 'session_secure_cookie';

    private const TITLE = 'Secure session cookie';

    public function evaluate(ConfigurationCatalog $catalog): Finding
    {
        $secure = $this->secure($catalog->setting('session.secure'));
        $scheme = $this->scheme($catalog->urlScheme);
        $evidence = [
            $this->environmentEvidence($catalog),
            $this->evidence('app.url_scheme', $scheme),
            new FindingEvidence('session.secure', $secure),
        ];

        if ($pass = $this->passOutsideProduction($catalog, self::ID, self::TITLE, $evidence)) {
            return $pass;
        }

        if ($scheme !== Observation::Https) {
            return $this->finding(
                self::ID,
                FindingStatus::Unknown,
                Severity::None,
                self::TITLE,
                'The session cookie Secure flag was not compared with an HTTPS deployment because the application URL scheme is not https.',
                $evidence,
                'Set the application URL to an absolute https URL before treating this cookie flag as a production HTTPS finding.',
            );
        }

        if ($secure === Observation::Enabled) {
            return $this->finding(
                self::ID,
                FindingStatus::Pass,
                Severity::None,
                self::TITLE,
                'The session cookie Secure flag is enabled for a production application served over HTTPS.',
                $evidence,
                null,
            );
        }

        if ($secure === Observation::Disabled) {
            return $this->finding(
                self::ID,
                FindingStatus::Fail,
                Severity::High,
                self::TITLE,
                'The session cookie Secure flag is disabled for a production application served over HTTPS.',
                $evidence,
                'Set the session cookie Secure flag so the cookie is only sent over HTTPS.',
            );
        }

        if ($secure === Observation::Missing) {
            return $this->finding(
                self::ID,
                FindingStatus::Risk,
                Severity::Medium,
                self::TITLE,
                'The session cookie Secure flag is not set for a production application served over HTTPS.',
                $evidence,
                'Set the session cookie Secure flag in production when the application URL uses HTTPS.',
            );
        }

        return $this->finding(
            self::ID,
            FindingStatus::Unknown,
            Severity::None,
            self::TITLE,
            'The session cookie Secure flag could not be evaluated because it is not a boolean.',
            $evidence,
            'Set the session cookie Secure flag to true or false.',
        );
    }

    private function secure(ConfigurationSetting $setting): Observation
    {
        if ($setting->state === ConfigurationState::Missing) {
            return Observation::Missing;
        }

        if ($setting->state !== ConfigurationState::Present || ! is_bool($setting->value)) {
            return Observation::Invalid;
        }

        return $setting->value ? Observation::Enabled : Observation::Disabled;
    }

    private function scheme(?string $scheme): Observation
    {
        return match ($scheme) {
            'https' => Observation::Https,
            'http' => Observation::Http,
            default => Observation::Missing,
        };
    }
}
