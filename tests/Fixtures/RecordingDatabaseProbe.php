<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Beacon\Health\DatabaseProbe;

/**
 * Records database probes without opening a connection.
 */
final class RecordingDatabaseProbe implements DatabaseProbe
{
    public int $calls = 0;

    public ?int $timeout = null;

    public bool $fail = false;

    public function selectOne(array $config, int $timeoutSeconds): void
    {
        $this->calls++;
        $this->timeout = $timeoutSeconds;

        if ($this->fail) {
            throw new \RuntimeException('mysql://beacon:database-password-not-in-output@db.internal/app');
        }
    }
}
