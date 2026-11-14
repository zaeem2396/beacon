<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Logs;

use Beacon\Logs\ExceptionCategory;
use Beacon\Logs\ExceptionFingerprint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExceptionFingerprintTest extends TestCase
{
    #[Test]
    public function equivalent_exceptions_share_a_stable_fingerprint(): void
    {
        $fingerprints = new ExceptionFingerprint();

        $left = $fingerprints->make(
            'RuntimeException',
            'Order 12345 failed',
            ExceptionCategory::Unknown,
            'OrderService.php',
            42,
        );
        $right = $fingerprints->make(
            'RuntimeException',
            'Order 99999 failed',
            ExceptionCategory::Unknown,
            'OrderService.php',
            42,
        );

        $this->assertSame($left, $right);
        $this->assertSame(32, strlen($left));
    }

    #[Test]
    public function distinct_exceptions_produce_distinct_fingerprints(): void
    {
        $fingerprints = new ExceptionFingerprint();

        $left = $fingerprints->make('RuntimeException', 'Payment failed', ExceptionCategory::Unknown, 'Pay.php', 10);
        $right = $fingerprints->make('LogicException', 'Payment failed', ExceptionCategory::Unknown, 'Pay.php', 10);

        $this->assertNotSame($left, $right);
    }
}
