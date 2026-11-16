<?php

declare(strict_types=1);

namespace Beacon\Requests;

use Beacon\Health\SafeName;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * Reads and writes request observations in the dedicated beacon_requests table.
 */
final class DatabaseRequestStore implements RequestStore
{
    public const TABLE = 'beacon_requests';

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly Repository $config,
    ) {
    }

    public function available(): bool
    {
        try {
            return Schema::connection($this->connection())->hasTable(self::TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    public function write(RequestObservation $observation): void
    {
        if (! $this->available()) {
            return;
        }

        try {
            $this->query()->insert([
                'request_id' => $observation->requestId,
                'correlation_id' => $observation->correlationId,
                'method' => $observation->method,
                'route_name' => $observation->routeName,
                'route_uri' => $observation->routeUri,
                'action' => $observation->action,
                'status' => $observation->status,
                'started_at' => $this->timestamp($observation->startedAt),
                'finished_at' => $observation->finishedAt === null ? null : $this->timestamp($observation->finishedAt),
                'duration_ms' => $observation->durationMs,
                'environment' => $observation->environment,
                'had_exception' => $observation->hadException,
                'exception_id' => $observation->exceptionId,
                'exception_class' => $observation->exceptionClass,
                'created_at' => now(),
            ]);
        } catch (QueryException) {
            // Collection must not break the application response.
        }
    }

    public function search(RequestQuery $query): array
    {
        if (! $this->available()) {
            return ['records' => [], 'total' => 0, 'truncated' => false];
        }

        $builder = $this->query()->orderByDesc('started_at')->orderByDesc('id');
        $this->applyFilters($builder, $query);
        $total = (int) (clone $builder)->count();
        $needed = min(RequestQuery::MAX_ANALYSIS, $query->limit * $query->page);
        $rows = $builder->limit($needed)->get();
        $records = [];

        foreach ($rows as $row) {
            $records[] = $this->hydrate($row);
        }

        return [
            'records' => $records,
            'total' => $total,
            'truncated' => $total > $needed,
        ];
    }

    public function prune(int $retentionHours): int
    {
        if (! $this->available() || $retentionHours < 1) {
            return 0;
        }

        $cutoff = now()->subHours($retentionHours);

        return $this->query()->where('started_at', '<', $cutoff)->delete();
    }

    /**
     * @param \Illuminate\Database\Query\Builder $builder
     */
    private function applyFilters(mixed $builder, RequestQuery $query): void
    {
        if ($query->since !== null) {
            $builder->where('started_at', '>=', $this->timestamp($query->since));
        }

        if ($query->until !== null) {
            $builder->where('started_at', '<=', $this->timestamp($query->until));
        }

        if ($query->method !== null) {
            $builder->where('method', $query->method);
        }

        if ($query->route !== null) {
            $builder->where(static function ($inner) use ($query): void {
                $inner->where('route_name', $query->route)->orWhere('route_uri', $query->route);
            });
        }

        if ($query->status !== null) {
            $builder->where('status', $query->status);
        }

        if ($query->minDurationMs !== null) {
            $builder->where('duration_ms', '>=', $query->minDurationMs);
        }

        if ($query->hadException === true) {
            $builder->where('had_exception', true);
        }

        if ($query->hadException === false) {
            $builder->where('had_exception', false);
        }

        if ($query->correlationId !== null) {
            $builder->where(static function ($inner) use ($query): void {
                $inner->where('correlation_id', $query->correlationId)
                    ->orWhere('request_id', $query->correlationId);
            });
        }
    }

    private function hydrate(object $row): RequestObservation
    {
        /** @var array<string, mixed> $data */
        $data = get_object_vars($row);

        $requestId = $this->stringValue($data['request_id'] ?? null) ?? 'unknown';
        $method = $this->stringValue($data['method'] ?? null) ?? 'UNKNOWN';
        $routeUri = $this->stringValue($data['route_uri'] ?? null) ?? RouteNormalizer::UNMATCHED;
        $startedAt = $this->stringValue($data['started_at'] ?? null)
            ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.vP');
        $finishedAt = $this->stringValue($data['finished_at'] ?? null);
        $correlationId = $this->stringValue($data['correlation_id'] ?? null);
        $routeName = $this->stringValue($data['route_name'] ?? null);
        $action = $this->stringValue($data['action'] ?? null);
        $environment = $this->stringValue($data['environment'] ?? null);
        $exceptionId = $this->stringValue($data['exception_id'] ?? null);
        $exceptionClass = $this->stringValue($data['exception_class'] ?? null);
        $status = $data['status'] ?? null;
        $durationMs = $data['duration_ms'] ?? null;

        return new RequestObservation(
            requestId: $requestId,
            correlationId: $correlationId,
            method: $method,
            routeName: $routeName,
            routeUri: $routeUri,
            action: $action,
            status: is_int($status) || (is_string($status) && ctype_digit($status)) ? (int) $status : null,
            startedAt: $this->iso($startedAt),
            finishedAt: $finishedAt === null ? null : $this->iso($finishedAt),
            durationMs: is_int($durationMs) || (is_string($durationMs) && ctype_digit($durationMs)) ? (int) $durationMs : null,
            environment: $environment,
            hadException: (bool) ($data['had_exception'] ?? false),
            exceptionId: $exceptionId,
            exceptionClass: $exceptionClass,
        );
    }

    private function stringValue(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return null;
    }

    private function query(): \Illuminate\Database\Query\Builder
    {
        return $this->db->connection($this->connection())->table(self::TABLE);
    }

    /**
     * Resolved connection name. Null config uses the application default.
     */
    private function connection(): string
    {
        $name = $this->config->get('beacon.requests.connection');

        if (is_string($name) && $name !== '' && SafeName::isIdentifier($name)) {
            return $name;
        }

        return $this->db->getDefaultConnection();
    }

    private function timestamp(string $iso): string
    {
        return (new \DateTimeImmutable($iso))->format('Y-m-d H:i:s.v');
    }

    private function iso(string $value): string
    {
        return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.vP');
    }
}
