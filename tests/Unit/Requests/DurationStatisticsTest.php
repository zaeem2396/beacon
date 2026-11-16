<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Requests;

use Beacon\Requests\DurationStatistics;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DurationStatisticsTest extends TestCase
{
    #[Test]
    public function p95_requires_at_least_five_samples(): void
    {
        $this->assertNull(DurationStatistics::p95([10, 20, 30, 40]));
        $this->assertSame(50.0, DurationStatistics::p95([10, 20, 30, 40, 50]));
    }

    #[Test]
    public function p99_requires_at_least_twenty_samples(): void
    {
        $nineteen = range(1, 19);
        $twenty = range(1, 20);

        $this->assertNull(DurationStatistics::p99($nineteen));
        $this->assertSame(20.0, DurationStatistics::p99($twenty));
    }

    #[Test]
    public function empty_inputs_stay_null(): void
    {
        $this->assertNull(DurationStatistics::average([]));
        $this->assertNull(DurationStatistics::median([]));
        $this->assertNull(DurationStatistics::maximum([]));
    }
}
