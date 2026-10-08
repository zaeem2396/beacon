<?php

declare(strict_types=1);

namespace Beacon\Contracts;

use Beacon\Security\Capability;

/**
 * Collects information about one area of the Laravel application and returns
 * it as normalized Beacon data.
 *
 * Inspectors know nothing about MCP, the CLI or any other consumer.
 *
 * @template-covariant TData of Data
 */
interface Inspector
{
    /**
     * The capability a caller must be granted before this inspector runs.
     */
    public function capability(): Capability;

    /**
     * @param array<string, scalar|null> $parameters
     *
     * @return TData
     */
    public function inspect(array $parameters = []): Data;
}
