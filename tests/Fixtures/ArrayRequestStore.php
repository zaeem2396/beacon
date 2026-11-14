<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Beacon\Requests\RequestObservation;
use Beacon\Requests\RequestQuery;
use Beacon\Requests\RequestStore;

/**
 * In-memory request store for tests when pdo_sqlite is unavailable.
 */
final class ArrayRequestStore implements RequestStore
{
    /**
     * @param list<RequestObservation> $records
     */
    public function __construct(
        private array $records = [],
        private bool $available = true,
    ) {
    }

    public function available(): bool
    {
        return $this->available;
    }

    public function write(RequestObservation $observation): void
    {
        if (! $this->available) {
            return;
        }

        $this->records[] = $observation;
    }

    public function search(RequestQuery $query): array
    {
        if (! $this->available) {
            return ['records' => [], 'total' => 0, 'truncated' => false];
        }

        $filtered = array_values(array_filter(
            $this->records,
            static function (RequestObservation $record) use ($query): bool {
                if ($query->method !== null && $record->method !== $query->method) {
                    return false;
                }

                if ($query->route !== null
                    && $record->routeName !== $query->route
                    && $record->routeUri !== $query->route) {
                    return false;
                }

                if ($query->status !== null && $record->status !== $query->status) {
                    return false;
                }

                if ($query->minDurationMs !== null
                    && ($record->durationMs === null || $record->durationMs < $query->minDurationMs)) {
                    return false;
                }

                if ($query->hadException !== null && $record->hadException !== $query->hadException) {
                    return false;
                }

                if ($query->correlationId !== null
                    && $record->correlationId !== $query->correlationId
                    && $record->requestId !== $query->correlationId) {
                    return false;
                }

                if ($query->since !== null && $record->startedAt < $query->since) {
                    return false;
                }

                if ($query->until !== null && $record->startedAt > $query->until) {
                    return false;
                }

                return true;
            },
        ));

        usort(
            $filtered,
            static fn (RequestObservation $left, RequestObservation $right): int => $right->startedAt <=> $left->startedAt,
        );

        $needed = min(RequestQuery::MAX_SCAN, $query->limit * $query->page);

        return [
            'records' => array_slice($filtered, 0, $needed),
            'total' => count($filtered),
            'truncated' => count($filtered) > $needed,
        ];
    }

    public function prune(int $retentionHours): int
    {
        if (! $this->available || $retentionHours < 1) {
            return 0;
        }

        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->sub(new \DateInterval('PT' . $retentionHours . 'H'))
            ->format('Y-m-d\\TH:i:s.vP');
        $before = count($this->records);
        $this->records = array_values(array_filter(
            $this->records,
            static fn (RequestObservation $record): bool => $record->startedAt >= $cutoff,
        ));

        return $before - count($this->records);
    }
}
