<?php

declare(strict_types=1);

namespace Beacon\Database;

interface StatusProbe
{
    /**
     * Run a read-only probe and return the raw server version plus latency.
     *
     * The configuration may contain credentials. Implementations must not
     * return it, log it, or include the driver error in an exception message
     * that Beacon will keep. The connection must not be registered as the
     * application default.
     *
     * @param array<string, mixed> $config
     */
    public function measure(array $config, int $timeoutSeconds): ProbeMeasurement;
}
