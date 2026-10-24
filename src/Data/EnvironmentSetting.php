<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * One allowlisted configuration fact.
 *
 * Present settings carry a safe classification or boolean, never an arbitrary
 * configuration value. Sensitive settings carry no value at all.
 */
final readonly class EnvironmentSetting extends DataObject
{
    public function __construct(
        public string $name,
        public ConfigurationState $state,
        public bool|string|null $value,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.]*$/', $name) !== 1) {
            throw new \InvalidArgumentException('Environment setting name must be a lowercase identifier.');
        }

        if ($state === ConfigurationState::Present) {
            if ($value === null || (is_string($value) && ! $this->safeString($value))) {
                throw new \InvalidArgumentException('A present environment setting needs a short safe value.');
            }

            return;
        }

        if ($value !== null) {
            throw new \InvalidArgumentException('Only a present environment setting may carry a value.');
        }
    }

    private function safeString(string $value): bool
    {
        return strlen($value) <= 64 && preg_match('/\p{Cc}/u', $value) !== 1 && preg_match('/^[A-Za-z0-9.:+_\/-]+$/', $value) === 1;
    }
}
