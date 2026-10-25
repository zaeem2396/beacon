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
 * The application cipher must be one of Laravel's supported values.
 *
 * A missing or unrecognised cipher is a confirmed misconfiguration. The
 * rejected value is not repeated.
 */
final class AppCipher extends ConfigurationCheck
{
    public const ID = 'app_cipher';

    private const TITLE = 'Application cipher';

    private const REMEDIATION = 'Set the application cipher to AES-256-CBC or AES-128-CBC.';

    public function evaluate(ConfigurationCatalog $catalog): Finding
    {
        $setting = $catalog->setting('app.cipher');
        $evidence = [$this->evidence('app.cipher', $this->observation($setting))];

        if ($setting->state === ConfigurationState::Present) {
            return $this->finding(
                self::ID,
                FindingStatus::Pass,
                Severity::None,
                self::TITLE,
                'The application cipher is a recognised value.',
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
                'The application cipher could not be read safely.',
                $evidence,
                self::REMEDIATION,
            );
        }

        return $this->finding(
            self::ID,
            FindingStatus::Fail,
            Severity::Medium,
            self::TITLE,
            $setting->state === ConfigurationState::Missing
                ? 'The application cipher is not set.'
                : 'The application cipher is not a recognised value.',
            $evidence,
            self::REMEDIATION,
        );
    }
}
