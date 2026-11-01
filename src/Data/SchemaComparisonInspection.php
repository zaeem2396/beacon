<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Schema\Prose;

/**
 * Structural differences between two schema snapshots.
 *
 * This is not proof that a deployment will fail. Rows were not read.
 */
final readonly class SchemaComparisonInspection extends DataObject
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    /**
     * @param list<SchemaDifference> $differences
     * @param list<string> $limitations
     * @param list<string> $warnings
     */
    public function __construct(
        public ComparisonSummary $summary,
        public ComparisonScope $scope,
        public SchemaPage $pagination,
        public array $differences,
        public array $limitations,
        public array $warnings,
    ) {
        if ($pagination->count !== count($differences)) {
            throw new \InvalidArgumentException('Comparison pagination count must match the returned differences.');
        }

        foreach ($differences as $difference) {
            if (! $difference instanceof SchemaDifference) {
                throw new \InvalidArgumentException('Comparison differences must be difference records.');
            }
        }

        if (count($limitations) > 8 || count($warnings) > 8) {
            throw new \InvalidArgumentException('Comparison inspection has too many notes.');
        }

        foreach ([...$limitations, ...$warnings] as $note) {
            if (! is_string($note) || ! Prose::isSafe($note)) {
                throw new \InvalidArgumentException('Comparison notes must be short prose.');
            }
        }

        $this->schemaVersion = self::SCHEMA_VERSION;
    }
}
