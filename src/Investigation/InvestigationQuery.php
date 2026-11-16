<?php

declare(strict_types=1);

namespace Beacon\Investigation;

use Beacon\Exceptions\InvalidInvestigationQueryException;
use Beacon\Logs\LogLevel;
use Beacon\Requests\CorrelationId;

/**
 * Validated investigation entry points. No generic query language.
 *
 * When multiple identifiers are supplied they are combined with AND for
 * evidence filtering. Primary subject priority is request_id, then
 * correlation_id, then exception_id, then fingerprint, then endpoint, then
 * the bounded time window.
 */
final class InvestigationQuery
{
    /**
     * @var list<string>
     */
    public const PARAMETERS = [
        'request_id',
        'correlation_id',
        'exception_id',
        'fingerprint',
        'endpoint',
        'since',
        'until',
        'severity',
        'max_results',
        'window_minutes',
    ];

    public function __construct(
        public readonly ?string $requestId,
        public readonly ?string $correlationId,
        public readonly ?string $exceptionId,
        public readonly ?string $fingerprint,
        public readonly ?string $endpoint,
        public readonly ?string $since,
        public readonly ?string $until,
        public readonly ?LogLevel $severity,
        public readonly ?int $maxResults,
        public readonly ?int $windowMinutes,
    ) {
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public static function from(array $parameters): self
    {
        foreach (array_keys($parameters) as $key) {
            if (! in_array($key, self::PARAMETERS, true)) {
                throw InvalidInvestigationQueryException::filter('Investigation rejected an unknown parameter.');
            }
        }

        $requestId = self::string($parameters, 'request_id');
        $correlationId = self::string($parameters, 'correlation_id');
        $exceptionId = self::string($parameters, 'exception_id');
        $fingerprint = self::string($parameters, 'fingerprint');
        $endpoint = self::string($parameters, 'endpoint');
        $since = self::string($parameters, 'since');
        $until = self::string($parameters, 'until');
        $severity = self::string($parameters, 'severity');
        $maxResults = self::nullableInt($parameters, 'max_results');
        $windowMinutes = self::nullableInt($parameters, 'window_minutes');

        if ($requestId !== null && ! CorrelationId::isValid($requestId)) {
            throw InvalidInvestigationQueryException::filter('Investigation rejected the request id.');
        }

        if ($correlationId !== null && ! CorrelationId::isValid($correlationId)) {
            throw InvalidInvestigationQueryException::filter('Investigation rejected the correlation id.');
        }

        if ($exceptionId !== null && preg_match('/^[a-f0-9]{16}$/', $exceptionId) !== 1) {
            throw InvalidInvestigationQueryException::filter('Investigation rejected the exception id.');
        }

        if ($fingerprint !== null && preg_match('/^[a-f0-9]{32}$/', $fingerprint) !== 1) {
            throw InvalidInvestigationQueryException::filter('Investigation rejected the fingerprint.');
        }

        if ($endpoint !== null && preg_match('#^[A-Za-z0-9_./{}:-]{1,191}$#', $endpoint) !== 1) {
            throw InvalidInvestigationQueryException::filter('Investigation rejected the endpoint.');
        }

        if ($since !== null && ! self::timestamp($since)) {
            throw InvalidInvestigationQueryException::filter('Investigation rejected the since filter.');
        }

        if ($until !== null && ! self::timestamp($until)) {
            throw InvalidInvestigationQueryException::filter('Investigation rejected the until filter.');
        }

        $level = null;

        if ($severity !== null) {
            $level = LogLevel::tryFromLoose($severity);

            if ($level === null) {
                throw InvalidInvestigationQueryException::filter('Investigation rejected the severity filter.');
            }
        }

        if ($maxResults !== null && ($maxResults < 1 || $maxResults > 100)) {
            throw InvalidInvestigationQueryException::filter('Investigation rejected max_results.');
        }

        if ($windowMinutes !== null && ($windowMinutes < 1 || $windowMinutes > InvestigationLimits::MAX_WINDOW_MINUTES)) {
            throw InvalidInvestigationQueryException::filter('Investigation rejected window_minutes.');
        }

        $normalizedSince = $since === null ? null : self::normalizeTimestamp($since);
        $normalizedUntil = $until === null ? null : self::normalizeTimestamp($until);

        if ($normalizedSince !== null && $normalizedUntil !== null && $normalizedSince > $normalizedUntil) {
            throw InvalidInvestigationQueryException::filter('Investigation rejected a since value after until.');
        }

        $hasFocus = $requestId !== null
            || $correlationId !== null
            || $exceptionId !== null
            || $fingerprint !== null
            || $endpoint !== null
            || $normalizedSince !== null
            || $normalizedUntil !== null
            || $level !== null;

        if (! $hasFocus && $windowMinutes === null && $maxResults === null) {
            // Allowed: default bounded time-window investigation.
        }

        return new self(
            $requestId,
            $correlationId,
            $exceptionId,
            $fingerprint,
            $endpoint,
            $normalizedSince,
            $normalizedUntil,
            $level,
            $maxResults,
            $windowMinutes,
        );
    }

    public function hasIdentifier(): bool
    {
        return $this->requestId !== null
            || $this->correlationId !== null
            || $this->exceptionId !== null
            || $this->fingerprint !== null
            || $this->endpoint !== null;
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
            throw InvalidInvestigationQueryException::filter('Investigation rejected a non-scalar filter.');
        }

        return trim((string) $value);
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

        throw InvalidInvestigationQueryException::filter('Investigation rejected a non-integer filter.');
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
