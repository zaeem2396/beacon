<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Beacon\Schema\SchemaQuery;
use Beacon\Schema\SchemaReader;
use Beacon\Schema\SchemaReadResult;

/**
 * Records schema reads without opening a connection.
 */
final class RecordingSchemaReader implements SchemaReader
{
    public int $calls = 0;

    public ?SchemaQuery $query = null;

    /** @var list<string> */
    public array $exclude = [];

    public bool $fail = false;

    public function read(array $config, int $timeoutSeconds, SchemaQuery $query, array $exclude): SchemaReadResult
    {
        $this->calls++;
        $this->query = $query;
        $this->exclude = $exclude;

        if ($this->fail) {
            throw new \RuntimeException('mysql://beacon:schema-password-not-in-output@db.internal/app');
        }

        return new SchemaReadResult('appdb', '8.0.36', 0, 0, [], false, false, false, false, false);
    }
}
