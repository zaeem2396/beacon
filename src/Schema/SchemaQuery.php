<?php

declare(strict_types=1);

namespace Beacon\Schema;

use Beacon\Exceptions\InvalidSchemaQueryException;
use Beacon\Health\SafeName;

/**
 * A validated schema inspection request.
 *
 * Filters are literals. There is no SQL, regular expression, or connection
 * configuration in the request. Omitted detail flags default to included.
 */
final readonly class SchemaQuery
{
    public const DEFAULT_LIMIT = 25;

    public const MAX_LIMIT = 50;

    public const MAX_PAGE = 10000;

    public const PARAMETERS = [
        'connection',
        'table',
        'include_views',
        'include_columns',
        'include_indexes',
        'include_foreign_keys',
        'page',
        'limit',
    ];

    public function __construct(
        public ?string $connection,
        public ?string $table,
        public bool $includeViews,
        public bool $includeColumns,
        public bool $includeIndexes,
        public bool $includeForeignKeys,
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
                throw InvalidSchemaQueryException::unknownParameter();
            }

            if ($value !== null && ! is_scalar($value)) {
                throw InvalidSchemaQueryException::type($key);
            }
        }

        return new self(
            self::identifier($parameters, 'connection'),
            self::identifier($parameters, 'table'),
            self::boolean($parameters, 'include_views'),
            self::boolean($parameters, 'include_columns'),
            self::boolean($parameters, 'include_indexes'),
            self::boolean($parameters, 'include_foreign_keys'),
            self::page($parameters),
            self::limit($parameters),
        );
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function identifier(array $parameters, string $key): ?string
    {
        if (! array_key_exists($key, $parameters) || $parameters[$key] === null) {
            return null;
        }

        $value = $parameters[$key];

        if (! is_string($value)) {
            throw InvalidSchemaQueryException::type($key);
        }

        $value = trim($value);

        if ($value === '') {
            throw InvalidSchemaQueryException::empty($key);
        }

        if (strlen($value) > 64) {
            throw InvalidSchemaQueryException::length($key);
        }

        if (! SafeName::isIdentifier($value)) {
            throw InvalidSchemaQueryException::characters($key);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function boolean(array $parameters, string $key): bool
    {
        if (! array_key_exists($key, $parameters) || $parameters[$key] === null) {
            return true;
        }

        if (! is_bool($parameters[$key])) {
            throw InvalidSchemaQueryException::type($key);
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
            throw InvalidSchemaQueryException::page();
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
            throw InvalidSchemaQueryException::limit();
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
            throw InvalidSchemaQueryException::type($key);
        }

        return $value;
    }
}
