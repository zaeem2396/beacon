<?php

declare(strict_types=1);

namespace Beacon\Comparison;

use Beacon\Exceptions\InvalidComparisonQueryException;
use Beacon\Health\SafeName;

/**
 * A validated request to compare two snapshots or one snapshot with the live schema.
 *
 * Snapshot bodies are JSON strings. The caller cannot pass SQL, PHP, or a filesystem path.
 */
final readonly class CompareQuery
{
    public const DEFAULT_LIMIT = 100;

    public const MAX_LIMIT = 100;

    public const MAX_PAGE = 10000;

    public const PARAMETERS = [
        'baseline',
        'candidate',
        'baseline_live',
        'candidate_live',
        'connection',
        'category',
        'severity',
        'object',
        'detect_renames',
        'page',
        'limit',
    ];

    public function __construct(
        public ?string $baseline,
        public ?string $candidate,
        public bool $baselineLive,
        public bool $candidateLive,
        public ?string $connection,
        public ?DifferenceCategory $category,
        public ?Severity $severity,
        public ?string $object,
        public bool $detectRenames,
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
                throw InvalidComparisonQueryException::unknownParameter();
            }

            if ($value !== null && ! is_scalar($value)) {
                throw InvalidComparisonQueryException::type($key);
            }
        }

        $baselineLive = self::boolean($parameters, 'baseline_live');
        $candidateLive = self::boolean($parameters, 'candidate_live');
        $baseline = self::document($parameters, 'baseline', $baselineLive);
        $candidate = self::document($parameters, 'candidate', $candidateLive);

        if ($baselineLive && $candidateLive) {
            throw InvalidComparisonQueryException::selection();
        }

        if (($baseline === null && ! $baselineLive) || ($candidate === null && ! $candidateLive)) {
            throw InvalidComparisonQueryException::selection();
        }

        return new self(
            $baseline,
            $candidate,
            $baselineLive,
            $candidateLive,
            self::connection($parameters),
            self::category($parameters),
            self::severity($parameters),
            self::object($parameters),
            self::boolean($parameters, 'detect_renames'),
            self::page($parameters),
            self::limit($parameters),
        );
    }

    public function filtered(): bool
    {
        return $this->category !== null || $this->severity !== null || $this->object !== null;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function document(array $parameters, string $key, bool $live): ?string
    {
        $present = array_key_exists($key, $parameters) && $parameters[$key] !== null;

        if ($live && $present) {
            throw InvalidComparisonQueryException::selection();
        }

        if (! $present) {
            return null;
        }

        if (! is_string($parameters[$key])) {
            throw InvalidComparisonQueryException::type($key);
        }

        return $parameters[$key];
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

        if (! is_string($value) || ! SafeName::isIdentifier($value)) {
            throw InvalidComparisonQueryException::type('connection');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function category(array $parameters): ?DifferenceCategory
    {
        if (! array_key_exists('category', $parameters) || $parameters['category'] === null) {
            return null;
        }

        if (! is_string($parameters['category'])) {
            throw InvalidComparisonQueryException::type('category');
        }

        $category = DifferenceCategory::tryFrom($parameters['category']);

        if ($category === null) {
            throw InvalidComparisonQueryException::category();
        }

        return $category;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function severity(array $parameters): ?Severity
    {
        if (! array_key_exists('severity', $parameters) || $parameters['severity'] === null) {
            return null;
        }

        if (! is_string($parameters['severity'])) {
            throw InvalidComparisonQueryException::type('severity');
        }

        $severity = Severity::tryFrom($parameters['severity']);

        if ($severity === null) {
            throw InvalidComparisonQueryException::severity();
        }

        return $severity;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function object(array $parameters): ?string
    {
        if (! array_key_exists('object', $parameters) || $parameters['object'] === null) {
            return null;
        }

        $value = $parameters['object'];

        if (! is_string($value) || ! SafeName::isIdentifier($value)) {
            throw InvalidComparisonQueryException::object();
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function boolean(array $parameters, string $key): bool
    {
        if (! array_key_exists($key, $parameters) || $parameters[$key] === null) {
            return false;
        }

        if (! is_bool($parameters[$key])) {
            throw InvalidComparisonQueryException::type($key);
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
            throw InvalidComparisonQueryException::page();
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
            throw InvalidComparisonQueryException::limit();
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
            throw InvalidComparisonQueryException::type($key);
        }

        return $value;
    }
}
