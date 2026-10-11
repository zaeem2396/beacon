<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures\Mcp;

use Beacon\Contracts\RequiresCapability;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Test-only tool that fails with secrets embedded in the exception message,
 * like a database driver error would.
 */
final class FailingTool extends Tool implements RequiresCapability
{
    public const SECRETS = [
        'hunter2-db-password',
        'base64:QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVowMTIzNDU2Nzg5',
    ];

    public function capability(): Capability
    {
        return InspectionCapability::Database->capability();
    }

    public function handle(Request $request): Response
    {
        throw new \RuntimeException('Connection failed for mysql://beacon:' . self::SECRETS[0] . '@db.internal/app with key ' . self::SECRETS[1]);
    }
}
