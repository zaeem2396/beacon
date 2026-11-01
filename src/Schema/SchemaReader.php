<?php

declare(strict_types=1);

namespace Beacon\Schema;

interface SchemaReader
{
    /**
     * Read one page of schema metadata and disconnect.
     *
     * The configuration may contain credentials. Implementations must not
     * return it, log it, or run anything other than a read-only metadata
     * statement. The connection must not be registered as the application default.
     *
     * @param array<string, mixed> $config
     * @param list<string> $exclude
     */
    public function read(array $config, int $timeoutSeconds, SchemaQuery $query, array $exclude): SchemaReadResult;
}
