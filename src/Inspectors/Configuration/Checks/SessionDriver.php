<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Configuration\Checks;

use Beacon\Data\ConfigurationCatalog;
use Beacon\Data\ConfigurationState;
use Beacon\Data\Finding;
use Beacon\Data\FindingStatus;
use Beacon\Data\Severity;
use Beacon\Inspectors\Configuration\ConfigurationCheck;

/**
 * The session driver must be present and recognised. There is no separate
 * connection name for this check, and the session store is not contacted.
 */
final class SessionDriver extends ConfigurationCheck
{
    public const ID = 'session_driver';

    private const TITLE = 'Session driver';

    public function evaluate(ConfigurationCatalog $catalog): Finding
    {
        $setting = $catalog->setting('session.driver');
        $evidence = [$this->evidence('session.driver', $this->observation($setting))];

        if ($setting->state === ConfigurationState::Present) {
            return $this->finding(
                self::ID,
                FindingStatus::Pass,
                Severity::None,
                self::TITLE,
                'The session driver is a recognised value.',
                $evidence,
                null,
            );
        }

        if ($setting->state === ConfigurationState::Sensitive) {
            return $this->finding(
                self::ID,
                FindingStatus::Unknown,
                Severity::None,
                self::TITLE,
                'The session driver could not be read safely.',
                $evidence,
                'Set the session driver to a recognised value.',
            );
        }

        return $this->finding(
            self::ID,
            FindingStatus::Fail,
            Severity::Medium,
            self::TITLE,
            $setting->state === ConfigurationState::Missing
                ? 'The session driver is not set.'
                : 'The session driver is not a recognised value.',
            $evidence,
            'Set the session driver to a recognised value.',
        );
    }
}
