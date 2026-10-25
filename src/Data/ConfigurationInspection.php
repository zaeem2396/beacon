<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * One page of allowlisted configuration settings plus findings about the
 * whole approved set.
 *
 * Findings are not limited to the current page or category filter.
 */
final readonly class ConfigurationInspection extends DataObject
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    /**
     * @param list<ConfigurationSetting> $settings
     * @param list<Finding> $findings
     */
    public function __construct(
        public ConfigurationPage $pagination,
        public array $settings,
        public array $findings,
    ) {
        if ($pagination->count !== count($settings)) {
            throw new \InvalidArgumentException('Configuration pagination count must match the returned settings.');
        }

        foreach ($settings as $setting) {
            if (! $setting instanceof ConfigurationSetting) {
                throw new \InvalidArgumentException('Configuration inspection settings must be configuration settings.');
            }
        }

        foreach ($findings as $finding) {
            if (! $finding instanceof Finding) {
                throw new \InvalidArgumentException('Configuration inspection findings must be findings.');
            }
        }

        $this->schemaVersion = self::SCHEMA_VERSION;
    }
}
