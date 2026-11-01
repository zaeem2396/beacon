<?php

declare(strict_types=1);

namespace Beacon\Migrations;

use Beacon\Exceptions\InvalidMigrationQueryException;
use Beacon\Health\SafeName;

/**
 * A validated migration inspection request.
 *
 * Filters are literals. There is no SQL, filesystem path outside the
 * registered set, PHP class, or Artisan command in the request.
 */
final readonly class MigrationQuery
{
    public const DEFAULT_LIMIT = 25;

    public const MAX_LIMIT = 100;

    public const MAX_PAGE = 10000;

    public const MAX_BATCH = 1000000;

    public const PARAMETERS = [
        'connection',
        'name',
        'status',
        'batch',
        'path',
        'page',
        'limit',
    ];

    public function __construct(
        public ?string $connection,
        public ?string $name,
        public ?MigrationStatus $status,
        public ?int $batch,
        public ?string $path,
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
                throw InvalidMigrationQueryException::unknownParameter();
            }

            if ($value !== null && ! is_scalar($value)) {
                throw InvalidMigrationQueryException::type($key);
            }
        }

        return new self(
            self::connection($parameters),
            self::name($parameters),
            self::status($parameters),
            self::batch($parameters),
            self::path($parameters),
            self::page($parameters),
            self::limit($parameters),
        );
    }

    public function filtered(): bool
    {
        return $this->name !== null || $this->status !== null || $this->batch !== null || $this->path !== null;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function connection(array $parameters): ?string
    {
        if (! array_key_exists('connection', $parameters) || $parameters['connection'] === null) {
            return null;
        }

        $value = $parameters['connection'];

        if (! is_string($value)) {
            throw InvalidMigrationQueryException::type('connection');
        }

        $value = trim($value);

        if ($value === '') {
            throw InvalidMigrationQueryException::empty('connection');
        }

        if (strlen($value) > 64) {
            throw InvalidMigrationQueryException::length('connection');
        }

        if (! SafeName::isIdentifier($value)) {
            throw InvalidMigrationQueryException::characters('connection');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function name(array $parameters): ?string
    {
        if (! array_key_exists('name', $parameters) || $parameters['name'] === null) {
            return null;
        }

        $value = $parameters['name'];

        if (! is_string($value)) {
            throw InvalidMigrationQueryException::type('name');
        }

        $value = trim($value);

        if ($value === '') {
            throw InvalidMigrationQueryException::empty('name');
        }

        if (strlen($value) > 64) {
            throw InvalidMigrationQueryException::length('name');
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_]*$/', $value) !== 1) {
            throw InvalidMigrationQueryException::characters('name');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function status(array $parameters): ?MigrationStatus
    {
        if (! array_key_exists('status', $parameters) || $parameters['status'] === null) {
            return null;
        }

        $value = $parameters['status'];

        if (! is_string($value)) {
            throw InvalidMigrationQueryException::type('status');
        }

        return MigrationStatus::tryFrom($value) ?? throw InvalidMigrationQueryException::status();
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function batch(array $parameters): ?int
    {
        $batch = self::integer($parameters, 'batch');

        if ($batch === null) {
            return null;
        }

        if ($batch < 1 || $batch > self::MAX_BATCH) {
            throw InvalidMigrationQueryException::batch();
        }

        return $batch;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function path(array $parameters): ?string
    {
        if (! array_key_exists('path', $parameters) || $parameters['path'] === null) {
            return null;
        }

        $value = $parameters['path'];

        if (! is_string($value)) {
            throw InvalidMigrationQueryException::type('path');
        }

        $value = trim($value);

        if ($value === '') {
            throw InvalidMigrationQueryException::empty('path');
        }

        if (strlen($value) > 180 || preg_match('#^[A-Za-z0-9][A-Za-z0-9_./-]*$#', $value) !== 1) {
            throw InvalidMigrationQueryException::characters('path');
        }

        if (str_contains($value, '..') || str_contains($value, '//') || str_starts_with($value, '/')) {
            throw InvalidMigrationQueryException::characters('path');
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
            throw InvalidMigrationQueryException::page();
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
            throw InvalidMigrationQueryException::limit();
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
            throw InvalidMigrationQueryException::type($key);
        }

        return $value;
    }
}
