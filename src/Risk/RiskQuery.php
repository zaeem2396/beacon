<?php

declare(strict_types=1);

namespace Beacon\Risk;

use Beacon\Exceptions\InvalidRiskQueryException;
use Beacon\Health\SafeName;
use Beacon\Migrations\MigrationName;

/**
 * A validated migration-risk request.
 *
 * Filters are literals. There is no SQL, PHP source, or absolute path.
 * enrich is set by Beacon after the schema capability is allowed. Callers
 * cannot supply it through MCP.
 */
final readonly class RiskQuery
{
    public const DEFAULT_LIMIT = 100;

    public const MAX_LIMIT = 100;

    public const MAX_PAGE = 10000;

    public const PARAMETERS = [
        'connection',
        'migration',
        'path',
        'category',
        'severity',
        'pending',
        'include_executed',
        'page',
        'limit',
        'enrich',
    ];

    public function __construct(
        public ?string $connection,
        public ?string $migration,
        public ?string $path,
        public ?RiskCategory $category,
        public ?Severity $minimumSeverity,
        public bool $pending,
        public bool $includeExecuted,
        public int $page,
        public int $limit,
        public bool $enrich,
    ) {
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    public static function from(array $parameters): self
    {
        foreach ($parameters as $key => $value) {
            if (! is_string($key) || ! in_array($key, self::PARAMETERS, true)) {
                throw InvalidRiskQueryException::unknownParameter();
            }

            if ($value !== null && ! is_scalar($value)) {
                throw InvalidRiskQueryException::type($key);
            }
        }

        $migration = self::migration($parameters);
        $pending = self::boolean($parameters, 'pending', true);
        $includeExecuted = self::boolean($parameters, 'include_executed', false);

        if (! $pending && ! $includeExecuted && $migration === null) {
            throw InvalidRiskQueryException::selection();
        }

        return new self(
            self::connection($parameters),
            $migration,
            self::path($parameters),
            self::category($parameters),
            self::severity($parameters),
            $pending,
            $includeExecuted,
            self::page($parameters),
            self::limit($parameters),
            self::boolean($parameters, 'enrich', false),
        );
    }

    public function filtered(): bool
    {
        return $this->migration !== null || $this->path !== null || $this->category !== null || $this->minimumSeverity !== null;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function connection(array $parameters): ?string
    {
        $value = self::string($parameters, 'connection');

        if ($value === null) {
            return null;
        }

        if (strlen($value) > 64 || ! SafeName::isIdentifier($value)) {
            throw strlen($value) > 64 ? InvalidRiskQueryException::length('connection') : InvalidRiskQueryException::characters('connection');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function migration(array $parameters): ?string
    {
        $value = self::string($parameters, 'migration');

        if ($value === null) {
            return null;
        }

        if (strlen($value) > 200 || ! MigrationName::isSafe($value)) {
            throw strlen($value) > 200 ? InvalidRiskQueryException::length('migration') : InvalidRiskQueryException::characters('migration');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function path(array $parameters): ?string
    {
        $value = self::string($parameters, 'path');

        if ($value === null) {
            return null;
        }

        if (strlen($value) > 180 || preg_match('#^[A-Za-z0-9][A-Za-z0-9_./-]*$#', $value) !== 1) {
            throw InvalidRiskQueryException::characters('path');
        }

        if (str_contains($value, '..') || str_contains($value, '//') || str_starts_with($value, '/')) {
            throw InvalidRiskQueryException::characters('path');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function category(array $parameters): ?RiskCategory
    {
        $value = self::string($parameters, 'category');

        if ($value === null) {
            return null;
        }

        return RiskCategory::tryFrom($value) ?? throw InvalidRiskQueryException::category();
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function severity(array $parameters): ?Severity
    {
        $value = self::string($parameters, 'severity');

        if ($value === null) {
            return null;
        }

        return Severity::tryFrom($value) ?? throw InvalidRiskQueryException::severity();
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function string(array $parameters, string $key): ?string
    {
        if (! array_key_exists($key, $parameters) || $parameters[$key] === null) {
            return null;
        }

        $value = $parameters[$key];

        if (! is_string($value)) {
            throw InvalidRiskQueryException::type($key);
        }

        $value = trim($value);

        if ($value === '') {
            throw InvalidRiskQueryException::empty($key);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function boolean(array $parameters, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $parameters) || $parameters[$key] === null) {
            return $default;
        }

        if (! is_bool($parameters[$key])) {
            throw InvalidRiskQueryException::type($key);
        }

        return $parameters[$key];
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
            throw InvalidRiskQueryException::page();
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
            throw InvalidRiskQueryException::limit();
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
            throw InvalidRiskQueryException::type($key);
        }

        return $value;
    }
}
