<?php

declare(strict_types=1);

namespace Beacon\Health;

interface CacheProbe
{
    /**
     * Read one fixed key and discard the value.
     *
     * Implementations must not put, increment, forget, or flush.
     */
    public function read(string $store): void;
}
