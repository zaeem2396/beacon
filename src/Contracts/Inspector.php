<?php

declare(strict_types=1);

namespace Beacon\Contracts;

/**
 * Collects information about one area of the Laravel application and returns
 * it as normalized Beacon data.
 *
 * Inspectors know nothing about MCP, the CLI or any other consumer. They must
 * use purpose-built, read-only operations and return typed {@see Data} with an
 * explicit set of fields, never whole configuration arrays or framework objects.
 *
 * @template-covariant TData of Data
 */
interface Inspector extends RequiresCapability
{
    /**
     * @param array<string, scalar|null> $parameters
     *
     * @return TData
     */
    public function inspect(array $parameters = []): Data;
}
