<?php

declare(strict_types=1);

namespace Beacon\Schema;

use Beacon\Data\SchemaObject;

/**
 * The page of objects read from one connection.
 *
 * This is not serialized to clients. The inspector wraps it in a schema inspection.
 */
final readonly class SchemaReadResult
{
    /**
     * @param list<SchemaObject> $objects
     */
    public function __construct(
        public ?string $database,
        public ?string $version,
        public int $tableCount,
        public int $viewCount,
        public array $objects,
        public bool $truncated,
        public bool $excluded,
        public bool $withheld,
        public bool $ignoredRule,
        public bool $estimates,
    ) {
    }
}
