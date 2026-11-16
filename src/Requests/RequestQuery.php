<?php

declare(strict_types=1);

namespace Beacon\Requests;

use Beacon\Exceptions\InvalidRequestQueryException;

/**
 * Validated filters for request inspection.
 */
final class RequestQuery
{
    public const DEFAULT_LIMIT = 25;

    public const MAX_LIMIT = 100;

    public const MAX_SCAN = 200;

    public const MAX_ANALYSIS = 1000;

    public const PARAMETERS = [
        'since',
        'until',
        'method',
        'route',
        'status',
        'min_duration_ms',
        'had_exception',
        'correlation_id',
        'limit',
        'page',
    ];

    public function __construct(
        public readonly ?string $since,
        public readonly ?string $until,
        public readonly ?string $method,
        public readonly ?string $route,
        public readonly ?int $status,
        public readonly ?int $minDurationMs,
        public readonly ?bool $hadException,
        public readonly ?string $correlationId,
        public readonly int $limit,
        public readonly int $page,
    ) {
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public static function from(array $parameters): self
    {
        foreach (array_keys($parameters) as $key) {
            if (! in_array($key, self::PARAMETERS, true)) {
                throw InvalidRequestQueryException::filter('Request inspection rejected an unknown parameter.');
            }
        }

        $since = self::string($parameters, 'since');
        $until = self::string($parameters, 'until');
        $method = self::string($parameters, 'method');
        $route = self::string($parameters, 'route');
        $correlation = self::string($parameters, 'correlation_id');
        $limit = self::int($parameters, 'limit', self::DEFAULT_LIMIT);
        $page = self::int($parameters, 'page', 1);
        $status = self::nullableInt($parameters, 'status');
        $minDuration = self::nullableInt($parameters, 'min_duration_ms');
        $hadException = self::nullableBool($parameters, 'had_exception');

        if ($since !== null && ! self::timestamp($since)) {
            throw InvalidRequestQueryException::filter('Request inspection rejected the since filter.');
        }

        if ($until !== null && ! self::timestamp($until)) {
            throw InvalidRequestQueryException::filter('Request inspection rejected the until filter.');
        }

        if ($method !== null) {
            $method = strtoupper($method);

            if (preg_match('/^[A-Z]{1,16}$/', $method) !== 1) {
                throw InvalidRequestQueryException::filter('Request inspection rejected the method filter.');
            }
        }

        if ($route !== null && preg_match('#^[A-Za-z0-9_./{}:-]{1,191}$#', $route) !== 1) {
            throw InvalidRequestQueryException::filter('Request inspection rejected the route filter.');
        }

        if ($correlation !== null && ! CorrelationId::isValid($correlation)) {
            throw InvalidRequestQueryException::filter('Request inspection rejected the correlation id.');
        }

        if ($status !== null && ($status < 100 || $status > 599)) {
            throw InvalidRequestQueryException::filter('Request inspection rejected the status filter.');
        }

        if ($minDuration !== null && ($minDuration < 0 || $minDuration > 3_600_000)) {
            throw InvalidRequestQueryException::filter('Request inspection rejected the minimum duration.');
        }

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw InvalidRequestQueryException::filter('Request inspection rejected the limit.');
        }

        if ($page < 1 || $page > 10000) {
            throw InvalidRequestQueryException::filter('Request inspection rejected the page.');
        }

        return new self(
            $since === null ? null : self::normalizeTimestamp($since),
            $until === null ? null : self::normalizeTimestamp($until),
            $method,
            $route,
            $status,
            $minDuration,
            $hadException,
            $correlation,
            $limit,
            $page,
        );
    }

    /**
     * Bounded window scan for analysis. Limit may exceed MAX_LIMIT up to MAX_ANALYSIS.
     */
    public static function analysis(
        ?string $since,
        ?string $until,
        ?string $method,
        ?string $route,
        ?int $status,
        ?int $minDurationMs,
        int $scan = self::MAX_ANALYSIS,
    ): self {
        return new self(
            $since,
            $until,
            $method,
            $route,
            $status,
            $minDurationMs,
            null,
            null,
            max(1, min(self::MAX_ANALYSIS, $scan)),
            1,
        );
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private static function string(array $parameters, string $key): ?string
    {
        if (! array_key_exists($key, $parameters) || $parameters[$key] === null || $parameters[$key] === '') {
            return null;
        }

        $value = $parameters[$key];

        if (! is_string($value) && ! is_int($value)) {
            throw InvalidRequestQueryException::filter('Request inspection rejected a non-scalar filter.');
        }

        return trim((string) $value);
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private static function int(array $parameters, string $key, int $default): int
    {
        if (! array_key_exists($key, $parameters) || $parameters[$key] === null || $parameters[$key] === '') {
            return $default;
        }

        $value = $parameters[$key];

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw InvalidRequestQueryException::filter('Request inspection rejected a non-integer filter.');
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private static function nullableInt(array $parameters, string $key): ?int
    {
        if (! array_key_exists($key, $parameters) || $parameters[$key] === null || $parameters[$key] === '') {
            return null;
        }

        $value = $parameters[$key];

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw InvalidRequestQueryException::filter('Request inspection rejected a non-integer filter.');
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private static function nullableBool(array $parameters, string $key): ?bool
    {
        if (! array_key_exists($key, $parameters) || $parameters[$key] === null || $parameters[$key] === '') {
            return null;
        }

        $value = $parameters[$key];

        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }

        if ($value === 0 || $value === '0' || $value === 'false') {
            return false;
        }

        throw InvalidRequestQueryException::filter('Request inspection rejected the exception filter.');
    }

    private static function timestamp(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:?\d{2})?)?$/', $value) === 1;
    }

    private static function normalizeTimestamp(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $value .= ' 00:00:00';
        }

        $value = str_replace('T', ' ', str_replace('Z', '+00:00', $value));

        return (new \DateTimeImmutable($value))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\\TH:i:s.vP');
    }
}
