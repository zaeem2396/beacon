<?php

declare(strict_types=1);

namespace Beacon\QueueIntelligence;

use Beacon\Data\FailedJobRecord;
use Beacon\Health\SafeName;
use Beacon\Runtime\TextSanitizer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Reads failed jobs without selecting or deserializing payloads.
 */
final class FailedJobReader
{
    public const DEFAULT_LIMIT = 25;

    public const MAX_LIMIT = 100;

    public function __construct(
        private readonly Repository $config,
        private readonly ConnectionResolverInterface $db,
    ) {
    }

    /**
     * @return array{driver: string, jobs: list<FailedJobRecord>, total: ?int, truncated: bool, warning: ?string}
     */
    public function read(int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $driver = $this->config->get('queue.failed.driver');

        if (! is_string($driver) || $driver === '' || $driver === 'null') {
            return [
                'driver' => 'none',
                'jobs' => [],
                'total' => 0,
                'truncated' => false,
                'warning' => 'No failed-job repository is configured.',
            ];
        }

        $canonical = strtolower($driver);

        if (! in_array($canonical, ['database', 'database-uuids'], true)) {
            return [
                'driver' => SafeName::isIdentifier($canonical) ? $canonical : 'unsupported',
                'jobs' => [],
                'total' => null,
                'truncated' => false,
                'warning' => 'The failed-job driver is not supported for inspection.',
            ];
        }

        $table = $this->config->get('queue.failed.table', 'failed_jobs');
        $connection = $this->config->get('queue.failed.database');

        if (! is_string($table) || ! SafeName::isIdentifier($table)) {
            return [
                'driver' => $canonical,
                'jobs' => [],
                'total' => null,
                'truncated' => false,
                'warning' => 'The failed-job table name is not safe to read.',
            ];
        }

        if ($connection !== null && (! is_string($connection) || ! SafeName::isIdentifier($connection))) {
            return [
                'driver' => $canonical,
                'jobs' => [],
                'total' => null,
                'truncated' => false,
                'warning' => 'The failed-job database connection is not safe to read.',
            ];
        }

        try {
            $query = $this->db->connection(is_string($connection) ? $connection : null)->table($table);
            $total = (int) $query->count();
            $rows = $query
                ->select(['id', 'uuid', 'connection', 'queue', 'exception', 'failed_at'])
                ->orderByDesc('id')
                ->limit($limit)
                ->get();
        } catch (\Throwable) {
            return [
                'driver' => $canonical,
                'jobs' => [],
                'total' => null,
                'truncated' => false,
                'warning' => 'The failed-job repository could not be read.',
            ];
        }

        $jobs = [];

        foreach ($rows as $row) {
            $exception = is_string($row->exception ?? null) ? (string) $row->exception : '';
            $jobs[] = new FailedJobRecord(
                failedJobId: $this->id($row),
                connection: $this->name($row->connection ?? null),
                queue: $this->name($row->queue ?? null),
                exceptionClass: $this->exceptionClass($exception),
                exceptionSummary: TextSanitizer::sanitize($this->exceptionMessage($exception)) ?? 'Job failed.',
                failedAt: $this->timestamp($row->failed_at ?? null),
            );
        }

        return [
            'driver' => $canonical,
            'jobs' => $jobs,
            'total' => $total,
            'truncated' => $total > count($jobs),
            'warning' => null,
        ];
    }

    private function id(object $row): string
    {
        if (isset($row->uuid) && is_string($row->uuid) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $row->uuid) === 1) {
            return $row->uuid;
        }

        if (isset($row->id) && (is_int($row->id) || (is_string($row->id) && ctype_digit($row->id)))) {
            return (string) $row->id;
        }

        return 'unknown';
    }

    private function name(mixed $value): ?string
    {
        return is_string($value) && SafeName::isIdentifier($value) ? $value : null;
    }

    private function exceptionClass(string $exception): ?string
    {
        if (preg_match('/^([A-Za-z0-9_\\\\]+(?:Exception|Error))/', $exception, $match) === 1) {
            $class = ltrim($match[1], '\\');

            return preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]{0,190}$/', $class) === 1 ? $class : null;
        }

        return null;
    }

    private function exceptionMessage(string $exception): string
    {
        $first = strtok($exception, "\n");

        return is_string($first) ? $first : 'Job failed.';
    }

    private function timestamp(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:sP');
        } catch (\Throwable) {
            return null;
        }
    }
}
