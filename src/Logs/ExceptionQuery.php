<?php

declare(strict_types=1);

namespace Beacon\Logs;

use Beacon\Exceptions\InvalidLogQueryException;
use Beacon\Health\SafeName;

/**
 * Validated filters for recent exception inspection.
 */
final class ExceptionQuery
{
    public const DEFAULT_LIMIT = 25;

    public const MAX_LIMIT = 100;

    /**
     * @var list<string>
     */
    public const PARAMETERS = ['channel', 'class', 'severity', 'category', 'since', 'until', 'limit', 'page'];

    public function __construct(
        public readonly ?string $channel,
        public readonly ?string $className,
        public readonly ?LogLevel $severity,
        public readonly ?ExceptionCategory $category,
        public readonly ?string $since,
        public readonly ?string $until,
        public readonly int $limit,
        public readonly int $page,
    ) {
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public static function from(array $parameters): self
    {
        $channel = self::string($parameters, 'channel');
        $class = self::string($parameters, 'class');
        $severity = self::string($parameters, 'severity');
        $category = self::string($parameters, 'category');
        $since = self::string($parameters, 'since');
        $until = self::string($parameters, 'until');
        $limit = self::int($parameters, 'limit', self::DEFAULT_LIMIT);
        $page = self::int($parameters, 'page', 1);

        foreach (array_keys($parameters) as $key) {
            if (! in_array($key, self::PARAMETERS, true)) {
                throw InvalidLogQueryException::filter('Exception inspection rejected an unknown parameter.');
            }
        }

        if ($channel !== null && ! SafeName::isIdentifier($channel)) {
            throw InvalidLogQueryException::channel();
        }

        if ($class !== null && preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]{0,190}$/', $class) !== 1) {
            throw InvalidLogQueryException::filter('Exception inspection rejected the class filter.');
        }

        $level = null;

        if ($severity !== null) {
            $level = LogLevel::tryFromLoose($severity);

            if ($level === null) {
                throw InvalidLogQueryException::filter('Exception inspection rejected the severity filter.');
            }
        }

        $categoryEnum = null;

        if ($category !== null) {
            $categoryEnum = ExceptionCategory::tryFrom(strtolower($category));

            if ($categoryEnum === null) {
                throw InvalidLogQueryException::filter('Exception inspection rejected the category filter.');
            }
        }

        if ($since !== null && ! self::timestamp($since)) {
            throw InvalidLogQueryException::filter('Exception inspection rejected the since filter.');
        }

        if ($until !== null && ! self::timestamp($until)) {
            throw InvalidLogQueryException::filter('Exception inspection rejected the until filter.');
        }

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw InvalidLogQueryException::filter('Exception inspection rejected the limit.');
        }

        if ($page < 1 || $page > 10000) {
            throw InvalidLogQueryException::filter('Exception inspection rejected the page.');
        }

        return new self($channel, $class, $level, $categoryEnum, $since, $until, $limit, $page);
    }

    public function scanLimit(): int
    {
        return min(LogReader::MAX_LIMIT, max($this->limit * $this->page, $this->limit));
    }

    public function rejects(LogRecord $log, string $class, ExceptionCategory $category): bool
    {
        if ($this->className !== null && strcasecmp($this->className, $class) !== 0) {
            return true;
        }

        if ($this->severity !== null && $log->level->rank() < $this->severity->rank()) {
            return true;
        }

        if ($this->category !== null && $this->category !== $category) {
            return true;
        }

        if ($this->since !== null && $log->timestamp < $this->normalize($this->since)) {
            return true;
        }

        if ($this->until !== null && $log->timestamp > $this->normalize($this->until)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private static function string(array $parameters, string $key): ?string
    {
        if (! array_key_exists($key, $parameters)) {
            return null;
        }

        $value = $parameters[$key];

        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            throw InvalidLogQueryException::filter('Exception inspection rejected a non-scalar filter.');
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
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

        throw InvalidLogQueryException::filter('Exception inspection rejected a non-integer filter.');
    }

    private static function timestamp(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:?\d{2})?)?$/', $value) === 1;
    }

    private function normalize(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $value .= ' 00:00:00';
        }

        $value = str_replace('T', ' ', str_replace('Z', '+00:00', $value));

        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\\TH:i:sP');
        } catch (\Throwable) {
            return str_replace(' ', 'T', $value);
        }
    }
}
