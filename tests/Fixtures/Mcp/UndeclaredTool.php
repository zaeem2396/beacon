<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures\Mcp;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Test-only tool that does not declare a capability.
 */
final class UndeclaredTool extends Tool
{
    public static int $calls = 0;

    public function handle(Request $request): Response
    {
        ++self::$calls;

        return Response::text('undeclared');
    }
}
