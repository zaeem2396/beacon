<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Beacon\Migrations\HistoryResult;
use Beacon\Migrations\MigrationHistory;

/**
 * Records repository reads and returns an empty history.
 */
final class RecordingMigrationHistory implements MigrationHistory
{
    public int $calls = 0;

    public bool $fail = false;

    /**
     * @var array<string, int>
     */
    public array $batches = [];

    public function read(array $config, int $timeoutSeconds, string $table): HistoryResult
    {
        $this->calls++;

        if ($this->fail) {
            throw new \RuntimeException('mysql://beacon:migration-password-not-in-output@db.internal/app');
        }

        return new HistoryResult(true, $this->batches === [], $this->batches, false);
    }
}
