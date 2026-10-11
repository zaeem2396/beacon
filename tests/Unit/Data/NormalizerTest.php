<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Data;

use Beacon\Data\Normalizer;
use Beacon\Exceptions\UnserializableValueException;
use Beacon\Health\HealthStatus;
use Beacon\Security\AccessMode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NormalizerTest extends TestCase
{
    #[Test]
    public function it_passes_scalars_and_null_through(): void
    {
        $this->assertSame(['a', 1, 1.5, true, null], Normalizer::normalize(['a', 1, 1.5, true, null]));
    }

    #[Test]
    public function it_normalizes_enums_and_dates(): void
    {
        $date = new \DateTimeImmutable('2026-10-09 10:00:00', new \DateTimeZone('UTC'));

        $this->assertSame(
            ['status' => 'critical', 'mode' => 'read', 'at' => '2026-10-09T10:00:00+00:00'],
            Normalizer::normalize(['status' => HealthStatus::Critical, 'mode' => AccessMode::Read, 'at' => $date]),
        );
    }

    #[Test]
    public function it_rejects_arbitrary_objects(): void
    {
        $this->expectException(UnserializableValueException::class);
        $this->expectExceptionMessage('stdClass');

        Normalizer::normalize(['nested' => [new \stdClass()]]);
    }

    #[Test]
    public function it_rejects_closures(): void
    {
        $this->expectException(UnserializableValueException::class);

        Normalizer::normalize(static fn (): string => 'secret');
    }

    #[Test]
    public function it_rejects_non_finite_floats(): void
    {
        $this->expectException(UnserializableValueException::class);

        Normalizer::normalize(NAN);
    }
}
