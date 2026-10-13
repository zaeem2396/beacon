<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Illuminate\Contracts\Foundation\MaintenanceMode;

/**
 * Test-only maintenance-mode driver that is either up, down, or broken in the
 * way a cache-backed driver fails when its store is unreachable.
 */
final class FakeMaintenanceMode implements MaintenanceMode
{
    public const SECRET = 'redis://beacon:maintenance-secret@cache.internal:6379';

    public function __construct(
        private readonly ?bool $active,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function activate(array $payload): void
    {
        throw new \LogicException('Beacon must never change maintenance mode.');
    }

    public function deactivate(): void
    {
        throw new \LogicException('Beacon must never change maintenance mode.');
    }

    public function active(): bool
    {
        return $this->active ?? throw new \RuntimeException('Connection refused: ' . self::SECRET);
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return [];
    }
}
