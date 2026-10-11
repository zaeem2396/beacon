<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures\Mcp;

use Beacon\Contracts\RequiresCapability;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;
use Beacon\Security\Principal;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Test-only tool that returns a constant string.')]
final class EchoTool extends Tool implements RequiresCapability
{
    public static int $calls = 0;

    public static ?Principal $caller = null;

    public function capability(): Capability
    {
        return InspectionCapability::Application->capability();
    }

    public function handle(Request $request, Principal $caller): Response
    {
        ++self::$calls;
        self::$caller = $caller;

        return Response::text('echo');
    }
}
