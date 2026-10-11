<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures\Mcp;

use Beacon\Contracts\RequiresCapability;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

final class EchoResource extends Resource implements RequiresCapability
{
    public static int $calls = 0;

    protected string $uri = 'beacon://tests/echo';

    protected string $mimeType = 'text/plain';

    public function capability(): Capability
    {
        return InspectionCapability::Routes->capability();
    }

    public function handle(Request $request): Response
    {
        ++self::$calls;

        return Response::text('resource');
    }
}
