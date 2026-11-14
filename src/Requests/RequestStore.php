<?php

declare(strict_types=1);

namespace Beacon\Requests;

/**
 * Persists and queries Beacon request observations.
 */
interface RequestStore
{
    public function available(): bool;

    public function write(RequestObservation $observation): void;

    /**
     * @return array{
     *     records: list<RequestObservation>,
     *     total: int,
     *     truncated: bool
     * }
     */
    public function search(RequestQuery $query): array;

    public function prune(int $retentionHours): int;
}
