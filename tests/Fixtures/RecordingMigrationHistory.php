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

    public function read(array $config, int $timeoutSeconds, string $table): HistoryResult
    {
        $this->calls++;

        return new HistoryResult(true, true, [], false);
    }
}
