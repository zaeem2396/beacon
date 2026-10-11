<?php

declare(strict_types=1);

namespace Beacon\Mcp;

use Beacon\Exceptions\BeaconException;

final class McpUnavailableException extends \RuntimeException implements BeaconException
{
    public static function because(string $reason): self
    {
        return new self("The Beacon MCP server is unavailable: {$reason}");
    }
}
