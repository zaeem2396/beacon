<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Beacon\Health\Clock;

final class FrozenClock implements Clock
{
    public function __construct(
        private readonly string $moment,
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->moment);
    }
}
