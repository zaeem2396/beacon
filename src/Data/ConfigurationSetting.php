<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * One allowlisted configuration fact.
 *
 * A present value is a boolean or a short classification. Sensitive settings
 * carry no value. Anything that is not on that list is rejected here, so a
 * secret cannot be stored on the setting even before redaction.
 */
final readonly class ConfigurationSetting extends DataObject
{
    /**
     * Session flags whose only safe value is a boolean.
     *
     * @var list<string>
     */
    private const FLAGS = [
        'session.secure',
        'session.http_only',
        'session.encrypt',
    ];

    private const CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.\\-';

    /**
     * @var list<string>
     */
    private const SENSITIVE_FRAGMENTS = [
        'password',
        'passwd',
        'secret',
        'token',
        'credential',
        'privatekey',
        'apikey',
    ];

    public function __construct(
        public ConfigurationCategory $category,
        public string $id,
        public ConfigurationState $state,
        public bool|string|null $value,
    ) {
        if (! $this->isIdentifier($id) || strlen($id) > 64) {
            throw new \InvalidArgumentException('Configuration setting id must be a lowercase identifier.');
        }

        if ($state === ConfigurationState::Present) {
            if (is_bool($value)) {
                if (! in_array($id, self::FLAGS, true)) {
                    throw new \InvalidArgumentException('Only a session flag may carry a boolean.');
                }

                return;
            }

            if (! is_string($value) || ! $this->safeString($value)) {
                throw new \InvalidArgumentException('A present configuration setting needs a short safe value.');
            }

            return;
        }

        if ($value !== null) {
            throw new \InvalidArgumentException('Only a present configuration setting may carry a value.');
        }
    }

    private function safeString(string $value): bool
    {
        $length = strlen($value);

        if ($length < 1 || $length > 120 || $this->containsSensitiveFragment($value)) {
            return false;
        }

        return $this->containsOnly($value, self::CHARACTERS);
    }

    private function isIdentifier(string $value): bool
    {
        $length = strlen($value);

        if ($length < 3 || ! str_contains('abcdefghijklmnopqrstuvwxyz', $value[0])) {
            return false;
        }

        return $this->containsOnly(substr($value, 1), 'abcdefghijklmnopqrstuvwxyz0123456789_.');
    }

    private function containsSensitiveFragment(string $value): bool
    {
        $compact = '';
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $character = $value[$index];

            if (str_contains('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', $character)) {
                $compact .= strtolower($character);
            }
        }

        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($compact, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function containsOnly(string $value, string $characters): bool
    {
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            if (! str_contains($characters, $value[$index])) {
                return false;
            }
        }

        return true;
    }
}
