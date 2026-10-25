<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Beacon\Health\CacheProbe;

final class ThrowingCacheProbe implements CacheProbe
{
    public int $calls = 0;

    public function read(string $store): void
    {
        $this->calls++;

        throw new \RuntimeException('cache-secret-not-in-output');
    }
}
