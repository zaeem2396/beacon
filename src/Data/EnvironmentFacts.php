<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * The configuration facts an environment check is allowed to see.
 *
 * Checks read this object and nothing else, so they cannot reach back into
 * Laravel for a value the inspector chose not to collect.
 */
final readonly class EnvironmentFacts extends DataObject
{
    /**
     * @param list<EnvironmentSetting> $settings
     */
    public function __construct(
        public string $environment,
        public ?bool $debug,
        public ?string $timezone,
        public ?string $locale,
        public ?bool $configurationCached,
        public ?bool $routesCached,
        public array $settings,
    ) {
        foreach ($settings as $setting) {
            if (! $setting instanceof EnvironmentSetting) {
                throw new \InvalidArgumentException('Environment facts settings must be environment settings.');
            }
        }
    }

    public function isProduction(): bool
    {
        return $this->environment === 'production';
    }

    public function setting(string $name): ?EnvironmentSetting
    {
        foreach ($this->settings as $setting) {
            if ($setting->name === $name) {
                return $setting;
            }
        }

        return null;
    }
}
