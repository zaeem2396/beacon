<?php

declare(strict_types=1);

namespace Beacon\Contracts;

use Beacon\Security\Capability;

/**
 * Anything that exposes application information (inspectors, MCP tools,
 * resources and prompts) declares the single capability a caller needs.
 */
interface RequiresCapability
{
    public function capability(): Capability;
}
