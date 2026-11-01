<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Schema\Prose;

/**
 * One page of schema metadata for an allowed database connection.
 *
 * The report does not contain table rows, credentials, or driver errors.
 */
final readonly class SchemaInspection extends DataObject
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    /**
     * @param list<SchemaObject> $objects
     * @param list<string> $warnings
     */
    public function __construct(
        public SchemaConnection $connection,
        public SchemaSummary $summary,
        public SchemaPage $pagination,
        public array $objects,
        public array $warnings,
    ) {
        if ($pagination->count !== count($objects)) {
            throw new \InvalidArgumentException('Schema pagination count must match the returned objects.');
        }

        if ($pagination->total !== $summary->tableCount + $summary->viewCount) {
            throw new \InvalidArgumentException('Schema pagination total must match the table and view counts.');
        }

        foreach ($objects as $object) {
            if (! $object instanceof SchemaObject) {
                throw new \InvalidArgumentException('Schema inspection objects must be schema objects.');
            }
        }

        if (count($warnings) > 8) {
            throw new \InvalidArgumentException('Schema inspection has too many warnings.');
        }

        foreach ($warnings as $warning) {
            if (! is_string($warning) || ! Prose::isSafe($warning)) {
                throw new \InvalidArgumentException('Schema warnings must be short prose.');
            }
        }

        $this->schemaVersion = self::SCHEMA_VERSION;
    }
}
