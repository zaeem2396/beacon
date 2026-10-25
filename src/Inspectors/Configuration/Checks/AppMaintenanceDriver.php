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
 * The maintenance driver is file or cache. An unset value is not a failure:
 * Laravel uses its own default. An unrecognised value is a confirmed failure.
 */
final class AppMaintenanceDriver extends ConfigurationCheck
{
    public const ID = 'app_maintenance_driver';

    private const TITLE = 'Maintenance driver';

    public function evaluate(ConfigurationCatalog $catalog): Finding
    {
        $setting = $catalog->setting('app.maintenance_driver');
        $evidence = [$this->evidence('app.maintenance_driver', $this->observation($setting))];

        if ($setting->state === ConfigurationState::Present) {
            return $this->finding(
                self::ID,
                FindingStatus::Pass,
                Severity::None,
                self::TITLE,
                'The maintenance driver is a recognised value.',
                $evidence,
                null,
            );
        }

        if ($setting->state === ConfigurationState::Missing) {
            return $this->finding(
                self::ID,
                FindingStatus::Pass,
                Severity::None,
                self::TITLE,
                'The maintenance driver is not set. Laravel uses its default.',
                $evidence,
                null,
            );
        }

        if ($setting->state === ConfigurationState::Invalid) {
            return $this->finding(
                self::ID,
                FindingStatus::Fail,
                Severity::Low,
                self::TITLE,
                'The maintenance driver is not a recognised value.',
                $evidence,
                'Set the maintenance driver to file or cache, or leave it unset to use Laravel\'s default.',
            );
        }

        return $this->finding(
            self::ID,
            FindingStatus::Unknown,
            Severity::None,
            self::TITLE,
            'The maintenance driver could not be read safely.',
            $evidence,
            'Set the maintenance driver to file or cache, or leave it unset to use Laravel\'s default.',
        );
    }
}
