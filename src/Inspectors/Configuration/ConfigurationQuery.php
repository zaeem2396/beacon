<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Configuration;

use Beacon\Data\ConfigurationCatalog;
use Beacon\Data\ConfigurationCategory;
use Beacon\Data\ConfigurationSetting;
use Beacon\Exceptions\InvalidConfigurationQueryException;

/**
 * A validated configuration query.
 *
 * Category and id must already be on the allowlist. There is no path,
 * wildcard, or regular-expression mode, so a caller cannot walk the
 * configuration repository by naming a nested key.
 */
final readonly class ConfigurationQuery
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 100;

    public const MAX_PAGE = 10000;

    public const PARAMETERS = ['category', 'id', 'page', 'limit'];

    public function __construct(
        public ?ConfigurationCategory $category,
        public ?string $id,
        public int $page,
        public int $limit,
    ) {
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    public static function from(array $parameters): self
    {
        foreach ($parameters as $key => $value) {
            if (! is_string($key) || ! in_array($key, self::PARAMETERS, true)) {
                throw InvalidConfigurationQueryException::unknownParameter();
            }

            if ($value !== null && ! is_scalar($value)) {
                throw InvalidConfigurationQueryException::type($key);
            }
        }

        $category = self::category($parameters);
        $id = self::id($parameters);

        if ($category !== null && $id !== null && ConfigurationCatalog::categoryFor($id) !== $category) {
            throw InvalidConfigurationQueryException::mismatch();
        }

        return new self($category, $id, self::page($parameters), self::limit($parameters));
    }

    public function matches(ConfigurationSetting $setting): bool
    {
        if ($this->category !== null && $setting->category !== $this->category) {
            return false;
        }

        if ($this->id !== null && $setting->id !== $this->id) {
            return false;
        }

        return true;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function category(array $parameters): ?ConfigurationCategory
    {
        $value = self::string($parameters, 'category', 32);

        if ($value === null) {
            return null;
        }

        $category = ConfigurationCategory::tryFrom(strtolower($value));

        if ($category === null) {
            throw InvalidConfigurationQueryException::category();
        }

        return $category;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function id(array $parameters): ?string
    {
        $value = self::string($parameters, 'id', 64);

        if ($value === null) {
            return null;
        }

        $id = strtolower($value);

        if (ConfigurationCatalog::categoryFor($id) === null) {
            throw InvalidConfigurationQueryException::id();
        }

        return $id;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function string(array $parameters, string $key, int $max): ?string
    {
        if (! array_key_exists($key, $parameters) || $parameters[$key] === null) {
            return null;
        }

        $value = $parameters[$key];

        if (! is_string($value)) {
            throw InvalidConfigurationQueryException::type($key);
        }

        $value = trim($value);

        if ($value === '') {
            throw InvalidConfigurationQueryException::empty($key);
        }

        if (strlen($value) > $max) {
            throw InvalidConfigurationQueryException::length($key);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function page(array $parameters): int
    {
        $page = self::integer($parameters, 'page');

        if ($page === null) {
            return 1;
        }

        if ($page < 1 || $page > self::MAX_PAGE) {
            throw InvalidConfigurationQueryException::page();
        }

        return $page;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function limit(array $parameters): int
    {
        $limit = self::integer($parameters, 'limit');

        if ($limit === null) {
            return self::DEFAULT_LIMIT;
        }

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw InvalidConfigurationQueryException::limit();
        }

        return $limit;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function integer(array $parameters, string $key): ?int
    {
        if (! array_key_exists($key, $parameters) || $parameters[$key] === null) {
            return null;
        }

        $value = $parameters[$key];

        if (is_bool($value) || ! is_int($value)) {
            throw InvalidConfigurationQueryException::type($key);
        }

        return $value;
    }
}
