<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Beacon\Database\ProbeMeasurement;
use Beacon\Database\StatusProbe;

/**
 * Records database status probes without opening a connection.
 */
final class RecordingStatusProbe implements StatusProbe
{
    public int $calls = 0;

    public ?int $timeout = null;

    public bool $fail = false;

    public string $version = '8.0.36-0ubuntu0.22.04.1';

    public function measure(array $config, int $timeoutSeconds): ProbeMeasurement
    {
        $this->calls++;
        $this->timeout = $timeoutSeconds;

        if ($this->fail) {
            throw new \RuntimeException('mysql://beacon:database-password-not-in-output@db.internal/app');
        }

        return new ProbeMeasurement($this->version, 4);
    }
}
