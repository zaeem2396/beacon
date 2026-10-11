<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Security;

use Beacon\Security\AccessMode;
use Beacon\Security\Capability;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CapabilityTest extends TestCase
{
    #[Test]
    public function it_creates_read_capabilities(): void
    {
        $capability = Capability::read('routes');

        $this->assertSame('routes', $capability->name);
        $this->assertSame(AccessMode::Read, $capability->mode);
        $this->assertTrue($capability->isReadOnly());
        $this->assertSame('routes:read', (string) $capability);
    }

    #[Test]
    public function write_capabilities_are_not_read_only(): void
    {
        $this->assertFalse((new Capability('cache', AccessMode::Write))->isReadOnly());
    }

    #[Test]
    public function it_rejects_invalid_names(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Capability::read('Routes:*');
    }
}
