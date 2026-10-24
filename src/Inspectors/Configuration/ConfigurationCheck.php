<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Configuration;

use Beacon\Data\ConfigurationCatalog;
use Beacon\Data\ConfigurationSetting;
use Beacon\Data\ConfigurationState;
use Beacon\Data\Finding;
use Beacon\Data\FindingEvidence;
use Beacon\Data\FindingStatus;
use Beacon\Data\Observation;
use Beacon\Data\Severity;

/**
 * One deterministic configuration check.
 *
 * Implementations decide from a {@see ConfigurationCatalog} only. They do not
 * read configuration, open connections, or perform network requests.
 */
abstract class ConfigurationCheck
{
    protected const OUTSIDE_PRODUCTION = 'This check only reports a misconfiguration when the environment is exactly "production".';

    abstract public function evaluate(ConfigurationCatalog $catalog): Finding;

    /**
     * @param list<FindingEvidence> $evidence
     */
    final protected function finding(
        string $id,
        FindingStatus $status,
        Severity $severity,
        string $title,
        string $description,
        array $evidence,
        ?string $remediation,
    ): Finding {
        return new Finding($id, $status, $severity, $title, $description, $evidence, $remediation);
    }

    final protected function evidence(string $name, Observation $observation): FindingEvidence
    {
        return new FindingEvidence($name, $observation);
    }

    final protected function observation(ConfigurationSetting $setting): Observation
    {
        return match ($setting->state) {
            ConfigurationState::Present => Observation::Present,
            ConfigurationState::Missing => Observation::Missing,
            ConfigurationState::Invalid => Observation::Invalid,
            ConfigurationState::Sensitive => Observation::Sensitive,
        };
    }

    final protected function environmentEvidence(ConfigurationCatalog $catalog): FindingEvidence
    {
        return $this->evidence(
            'environment',
            $catalog->isProduction() ? Observation::Production : Observation::NotProduction,
        );
    }

    /**
     * @param list<FindingEvidence> $evidence
     */
    final protected function passOutsideProduction(ConfigurationCatalog $catalog, string $id, string $title, array $evidence): ?Finding
    {
        if ($catalog->isProduction()) {
            return null;
        }

        return $this->finding($id, FindingStatus::Pass, Severity::None, $title, self::OUTSIDE_PRODUCTION, $evidence, null);
    }
}
