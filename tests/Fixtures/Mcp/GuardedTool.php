<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures\Mcp;

use Beacon\Contracts\RequiresCapability;
use Beacon\Security\AccessMode;
use Beacon\Security\Capability;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Test-only tool whose declared capability can be swapped per test.
 */
final class GuardedTool extends Tool implements RequiresCapability
{
    public static int $calls = 0;

    public static string $capabilityName = 'internal';

    public static AccessMode $mode = AccessMode::Read;

    public function capability(): Capability
    {
        return new Capability(self::$capabilityName, self::$mode);
    }

    public function handle(Request $request): Response
    {
        ++self::$calls;

        return Response::text('guarded');
    }

    public static function reset(): void
    {
        self::$calls = 0;
        self::$capabilityName = 'internal';
        self::$mode = AccessMode::Read;
    }
}
