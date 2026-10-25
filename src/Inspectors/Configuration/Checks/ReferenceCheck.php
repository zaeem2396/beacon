<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Configuration\Checks;

use Beacon\Data\ConfigurationCatalog;
use Beacon\Data\ConfigurationState;
use Beacon\Data\Finding;
use Beacon\Data\FindingEvidence;
use Beacon\Data\FindingStatus;
use Beacon\Data\Severity;
use Beacon\Inspectors\Configuration\ConfigurationCheck;

/**
 * Checks that a named default points at a registered definition with a
 * recognised driver.
 *
 * The description never includes the configured name, driver, or level. Those
 * values stay on the setting when they were safe to disclose, and are absent
 * when they were not.
 */
abstract class ReferenceCheck extends ConfigurationCheck
{
    public function evaluate(ConfigurationCatalog $catalog): Finding
    {
        $name = $catalog->setting($this->nameId());
        $definition = $catalog->setting($this->definitionId());
        $driver = $catalog->setting($this->driverId());
        $evidence = [
            $this->evidence($this->nameId(), $this->observation($name)),
            $this->evidence($this->definitionId(), $this->observation($definition)),
            $this->evidence($this->driverId(), $this->observation($driver)),
            ...$this->additionalEvidence($catalog),
        ];

        if ($name->state === ConfigurationState::Missing) {
            return $this->fail($evidence, 'The ' . $this->subject() . ' is not set.', 'Set the ' . $this->subject() . ' to a registered name.');
        }

        if ($name->state === ConfigurationState::Invalid) {
            return $this->unknown($evidence, 'The ' . $this->subject() . ' could not be read safely.');
        }

        if ($definition->state === ConfigurationState::Invalid) {
            if ($name->state !== ConfigurationState::Present) {
                return $this->unknown($evidence, 'The ' . $this->subject() . ' could not be read safely.');
            }

            return $this->fail($evidence, 'The ' . $this->subject() . ' is not registered.', 'Register the ' . $this->subject() . ', or point the default at a name that exists.');
        }

        if ($definition->state !== ConfigurationState::Present) {
            return $this->unknown($evidence, 'The ' . $this->subject() . ' could not be read safely.');
        }

        if ($driver->state === ConfigurationState::Missing) {
            return $this->fail($evidence, 'The ' . $this->subject() . ' does not name a driver.', 'Set a recognised driver on the ' . $this->subject() . '.');
        }

        if ($driver->state === ConfigurationState::Invalid) {
            return $this->fail($evidence, 'The ' . $this->subject() . ' uses a driver that is not recognised.', 'Set the ' . $this->subject() . ' driver to a recognised value.');
        }

        if ($driver->state !== ConfigurationState::Present) {
            return $this->unknown($evidence, 'The ' . $this->subject() . ' driver could not be read safely.');
        }

        $blocked = $this->blocked($catalog, $evidence);

        if ($blocked !== null) {
            return $blocked;
        }

        $description = 'The ' . $this->subject() . ' is registered and uses a recognised driver.';

        if ($name->state === ConfigurationState::Sensitive) {
            $description .= ' Its name was withheld.';
        }

        return $this->finding($this->checkId(), FindingStatus::Pass, Severity::None, $this->title(), $description, $evidence, null);
    }

    abstract protected function checkId(): string;

    abstract protected function title(): string;

    abstract protected function nameId(): string;

    abstract protected function definitionId(): string;

    abstract protected function driverId(): string;

    /**
     * A fixed noun phrase, such as "default database connection".
     */
    abstract protected function subject(): string;

    /**
     * @return list<FindingEvidence>
     */
    protected function additionalEvidence(ConfigurationCatalog $catalog): array
    {
        return [];
    }

    /**
     * @param list<FindingEvidence> $evidence
     */
    protected function blocked(ConfigurationCatalog $catalog, array $evidence): ?Finding
    {
        return null;
    }

    /**
     * @param list<FindingEvidence> $evidence
     */
    final protected function fail(array $evidence, string $description, string $remediation): Finding
    {
        return $this->finding($this->checkId(), FindingStatus::Fail, Severity::Medium, $this->title(), $description, $evidence, $remediation);
    }

    /**
     * @param list<FindingEvidence> $evidence
     */
    final protected function unknown(array $evidence, string $description): Finding
    {
        return $this->finding(
            $this->checkId(),
            FindingStatus::Unknown,
            Severity::None,
            $this->title(),
            $description,
            $evidence,
            'Use a short name that does not contain credentials so this check can be evaluated.',
        );
    }
}
