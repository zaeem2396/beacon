<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Environment\Checks;

use Beacon\Data\ConfigurationState;
use Beacon\Data\EnvironmentFacts;
use Beacon\Data\Finding;
use Beacon\Data\FindingEvidence;
use Beacon\Data\FindingStatus;
use Beacon\Data\Observation;
use Beacon\Data\Severity;
use Beacon\Inspectors\Environment\EnvironmentCheck;

/**
 * Reports whether an application key is configured, never what it is.
 */
final class AppKeyConfigured extends EnvironmentCheck
{
    public const ID = 'app_key_configured';

    private const TITLE = 'Application key';

    public function evaluate(EnvironmentFacts $facts): Finding
    {
        $observation = match ($facts->setting('app.key')?->state) {
            ConfigurationState::Sensitive => Observation::Present,
            ConfigurationState::Missing => Observation::Missing,
            default => Observation::Invalid,
        };
        $evidence = [new FindingEvidence('app.key', $observation)];

        if ($observation === Observation::Present) {
            return $this->finding(
                self::ID,
                FindingStatus::Pass,
                Severity::None,
                self::TITLE,
                'An application key is configured. The key is not included.',
                $evidence,
                null,
            );
        }

        if ($observation === Observation::Missing) {
            return $this->finding(
                self::ID,
                FindingStatus::Fail,
                Severity::High,
                self::TITLE,
                'No application key is configured.',
                $evidence,
                'Set an application key. Beacon reports only that the key is missing, never the key itself.',
            );
        }

        return $this->finding(
            self::ID,
            FindingStatus::Unknown,
            Severity::None,
            self::TITLE,
            'The application key could not be evaluated.',
            $evidence,
            'Configure app.key as a string. Beacon reports only whether a key is present, never the key itself.',
        );
    }
}
