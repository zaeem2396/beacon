<?php

declare(strict_types=1);

namespace Beacon\Health;

use Illuminate\Contracts\Cache\Factory;

/**
 * Reads a fixed key from one store and discards whatever comes back.
 */
final class LaravelCacheProbe implements CacheProbe
{
    public const KEY = 'beacon:health:probe';

    public function __construct(
        private readonly Factory $cache,
    ) {
    }

    public function read(string $store): void
    {
        $this->cache->store($store)->get(self::KEY);
    }
}
