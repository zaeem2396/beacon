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
 * Checks the session cookie SameSite policy in production.
 *
 * Lax and strict pass. None is a potential risk because it is sometimes
 * required for a cross-site flow, so it is not reported as a confirmed
 * misconfiguration.
 */
final class SessionSameSiteInProduction extends EnvironmentCheck
{
    public const ID = 'session_same_site_in_production';

    private const TITLE = 'Session cookie SameSite in production';

    public function evaluate(EnvironmentFacts $facts): Finding
    {
        $observation = $this->observation($facts->setting('session.same_site'));
        $evidence = [
            $this->environmentEvidence($facts),
            new FindingEvidence('session.same_site', $observation),
        ];

        if ($pass = $this->passOutsideProduction($facts, self::ID, self::TITLE, $evidence)) {
            return $pass;
        }

        if ($observation === Observation::Lax || $observation === Observation::Strict) {
            return $this->finding(
                self::ID,
                FindingStatus::Pass,
                Severity::None,
                self::TITLE,
                'The session cookie SameSite policy is restricted in production.',
                $evidence,
                null,
            );
        }

        if ($observation === Observation::None) {
            return $this->finding(
                self::ID,
                FindingStatus::Risk,
                Severity::Low,
                self::TITLE,
                'The session cookie SameSite policy is None, so the cookie is sent on cross-site requests.',
                $evidence,
                'Prefer lax or strict unless a cross-site flow requires None, and keep the Secure flag enabled.',
            );
        }

        return $this->finding(
            self::ID,
            FindingStatus::Unknown,
            Severity::None,
            self::TITLE,
            'The session cookie SameSite policy could not be evaluated because session.same_site is missing or not lax, strict, or none.',
            $evidence,
            'Set SESSION_SAME_SITE to lax, strict, or none so this check can be evaluated.',
        );
    }

    private function observation(?EnvironmentSetting $setting): Observation
    {
        if (! $setting instanceof EnvironmentSetting || $setting->state === ConfigurationState::Invalid) {
            return Observation::Invalid;
        }

        if ($setting->state === ConfigurationState::Missing) {
            return Observation::Missing;
        }

        return match ($setting->value) {
            'lax' => Observation::Lax,
            'strict' => Observation::Strict,
            'none' => Observation::None,
            default => Observation::Invalid,
        };
    }
}
