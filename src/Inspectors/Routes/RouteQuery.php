<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Routes;

use Beacon\Data\RouteRecord;
use Beacon\Exceptions\InvalidRouteQueryException;

/**
 * A validated route query.
 *
 * Filters are literals. contains is a substring check and exact is equality.
 * A leading slash on a URI filter is ignored so "/users" and "users" match
 * the same Laravel URI. There is no regular-expression mode.
 */
final readonly class RouteQuery
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 100;

    public const MAX_PAGE = 10000;

    private const PARAMETERS = ['method', 'uri', 'uri_match', 'name', 'name_match', 'middleware', 'page', 'limit'];

    public function __construct(
        public ?string $method,
        public ?string $uri,
        public MatchMode $uriMatch,
        public ?string $name,
        public MatchMode $nameMatch,
        public ?string $middleware,
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
                throw InvalidRouteQueryException::unknownParameter();
            }

            if ($value !== null && ! is_scalar($value)) {
                throw InvalidRouteQueryException::type($key);
            }
        }

        return new self(
            self::method($parameters),
            self::text($parameters, 'uri', RouteText::MAX_URI),
            self::mode($parameters, 'uri_match'),
            self::text($parameters, 'name', RouteText::MAX_NAME),
            self::mode($parameters, 'name_match'),
            self::text($parameters, 'middleware', RouteText::MAX_MIDDLEWARE),
            self::page($parameters),
            self::limit($parameters),
        );
    }

    public function matches(RouteRecord $route): bool
    {
        if ($this->method !== null && ! in_array($this->method, $route->methods, true)) {
            return false;
        }

        if ($this->uri !== null && ! $this->uriMatches($route->uri)) {
            return false;
        }

        if ($this->name !== null && ! $this->textMatches($route->name, $this->name, $this->nameMatch)) {
            return false;
        }

        if ($this->middleware !== null && ! in_array($this->middleware, $route->middleware, true)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function method(array $parameters): ?string
    {
        $value = self::string($parameters, 'method', 16);

        if ($value === null) {
            return null;
        }

        $verb = strtoupper($value);

        if (! in_array($verb, RouteRecord::METHODS, true)) {
            throw InvalidRouteQueryException::method();
        }

        return $verb;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function mode(array $parameters, string $key): MatchMode
    {
        $value = self::string($parameters, $key, 16);

        if ($value === null) {
            return MatchMode::Contains;
        }

        $mode = MatchMode::tryFrom($value);

        if ($mode === null) {
            throw InvalidRouteQueryException::match($key);
        }

        return $mode;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private static function text(array $parameters, string $key, int $max): ?string
    {
        $value = self::string($parameters, $key, $max);

        if ($value === null) {
            return null;
        }

        $valid = match ($key) {
            'uri' => RouteText::isUri($value),
            'name' => RouteText::isName($value),
            'middleware' => RouteText::isMiddleware($value),
            default => false,
        };

        if (! $valid) {
            throw InvalidRouteQueryException::characters($key);
        }

        return $value;
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
            throw InvalidRouteQueryException::type($key);
        }

        $value = trim($value);

        if ($value === '') {
            throw InvalidRouteQueryException::empty($key);
        }

        if (strlen($value) > $max) {
            throw InvalidRouteQueryException::length($key);
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
            throw InvalidRouteQueryException::page();
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
            throw InvalidRouteQueryException::limit();
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
            throw InvalidRouteQueryException::type($key);
        }

        return $value;
    }

    private function uriMatches(?string $uri): bool
    {
        if ($uri === null || $this->uri === null) {
            return false;
        }

        $filter = self::withoutLeadingSlash($this->uri);
        $candidate = self::withoutLeadingSlash($uri);

        if ($this->uriMatch === MatchMode::Exact) {
            return $candidate === $filter;
        }

        return $filter !== '' && str_contains($candidate, $filter);
    }

    private function textMatches(?string $value, string $filter, MatchMode $mode): bool
    {
        if ($value === null) {
            return false;
        }

        return $mode === MatchMode::Exact ? $value === $filter : str_contains($value, $filter);
    }

    private static function withoutLeadingSlash(string $value): string
    {
        if ($value !== '/' && str_starts_with($value, '/')) {
            return substr($value, 1);
        }

        return $value;
    }
}
