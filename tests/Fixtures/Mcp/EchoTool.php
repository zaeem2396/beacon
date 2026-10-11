<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures\Mcp;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Test-only tool that returns a constant string.')]
final class EchoTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text('echo');
    }
}
