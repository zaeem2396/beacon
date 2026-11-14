<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Comparison\SnapshotDocument;
use Beacon\Schema\Prose;

/**
 * A read-only schema snapshot.
 *
 * The nested snapshot is safe to save and compare later. It has no credentials
 * and no table rows.
 */
final readonly class SchemaSnapshotInspection extends DataObject
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public SchemaConnection $connection,
        public SnapshotDocument $snapshot,
        public array $warnings,
    ) {
        if (count($warnings) > 8) {
            throw new \InvalidArgumentException('Schema snapshot has too many warnings.');
        }

        foreach ($warnings as $warning) {
            if (! is_string($warning) || ! Prose::isSafe($warning)) {
                throw new \InvalidArgumentException('Schema snapshot warnings must be short prose.');
            }
        }

        $this->schemaVersion = self::SCHEMA_VERSION;
    }
}
