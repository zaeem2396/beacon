<?php

declare(strict_types=1);

namespace Beacon\Logs;

use Beacon\Data\ExceptionRecord;
use Beacon\Exceptions\InvalidLogQueryException;

/**
 * Filters for error aggregation over an inspected exception window.
 */
final class AggregateQuery
{
    public const DEFAULT_LIMIT = 25;

    public const MAX_LIMIT = 100;

    /**
     * @var list<string>
     */
    public const PARAMETERS = ['channel', 'class', 'severity', 'category', 'since', 'until', 'min_count', 'limit', 'page', 'sort'];

    public function __construct(
        public readonly ?string $className,
        public readonly ?LogLevel $severity,
        public readonly ?ExceptionCategory $category,
        public readonly ?string $channel,
        public readonly ?string $since,
        public readonly ?string $until,
        public readonly int $minCount,
        public readonly int $limit,
        public readonly int $page,
        public readonly string $sort,
    ) {
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public static function from(array $parameters): self
    {
        foreach (array_keys($parameters) as $key) {
            if (! in_array($key, self::PARAMETERS, true)) {
                throw InvalidLogQueryException::filter('Error aggregation rejected an unknown parameter.');
            }
        }

        $base = ExceptionQuery::from(array_intersect_key($parameters, array_flip(['channel', 'class', 'severity', 'category', 'since', 'until', 'limit', 'page'])));
        $minCount = 1;
        $sort = 'occurrences';

        if (array_key_exists('min_count', $parameters) && $parameters['min_count'] !== null && $parameters['min_count'] !== '') {
            $value = $parameters['min_count'];
            $minCount = is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : -1);

            if ($minCount < 1 || $minCount > 10000) {
                throw InvalidLogQueryException::filter('Error aggregation rejected the minimum count.');
            }
        }

        if (array_key_exists('sort', $parameters) && $parameters['sort'] !== null && $parameters['sort'] !== '') {
            $sortValue = is_string($parameters['sort']) ? $parameters['sort'] : '';

            if (! in_array($sortValue, ['occurrences', 'last_seen'], true)) {
                throw InvalidLogQueryException::filter('Error aggregation rejected the sort.');
            }

            $sort = $sortValue;
        }

        return new self(
            $base->className,
            $base->severity,
            $base->category,
            $base->channel,
            $base->since,
            $base->until,
            $minCount,
            $base->limit,
            $base->page,
            $sort,
        );
    }

    public function exceptionQuery(): ExceptionQuery
    {
        return new ExceptionQuery(
            $this->channel,
            $this->className,
            $this->severity,
            $this->category,
            $this->since,
            $this->until,
            min(LogReader::MAX_LIMIT, $this->limit * max(1, $this->page) * 4),
            1,
        );
    }

    public function rejects(ExceptionRecord $record): bool
    {
        if ($this->className !== null && strcasecmp($this->className, $record->className) !== 0) {
            return true;
        }

        if ($this->severity !== null && $record->severity->rank() < $this->severity->rank()) {
            return true;
        }

        if ($this->category !== null && $this->category !== $record->category) {
            return true;
        }

        return false;
    }
}
