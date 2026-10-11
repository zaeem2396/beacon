<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures\Mcp;

use Beacon\Contracts\RequiresCapability;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;

final class EchoPrompt extends Prompt implements RequiresCapability
{
    public static int $calls = 0;

    public function capability(): Capability
    {
        return InspectionCapability::Exceptions->capability();
    }

    public function handle(Request $request): Response
    {
        ++self::$calls;

        return Response::text('prompt');
    }
}
