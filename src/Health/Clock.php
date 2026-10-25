<?php

declare(strict_types=1);

namespace Beacon\Health;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
