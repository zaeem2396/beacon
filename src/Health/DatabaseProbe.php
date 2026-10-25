<?php

declare(strict_types=1);

namespace Beacon\Health;

interface DatabaseProbe
{
    /**
     * Run one read-only probe against a copy of a connection configuration.
     *
     * The configuration may contain credentials. Implementations must not
     * return it, log it, or register the connection as the application default.
     *
     * @param array<string, mixed> $config
     */
    public function selectOne(array $config, int $timeoutSeconds): void;
}
