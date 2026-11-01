<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Schema\Prose;

/**
 * One page of migration files compared with the migration repository.
 *
 * The report does not contain migration PHP, credentials, or absolute paths.
 */
final readonly class MigrationInspection extends DataObject
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    /**
     * @param list<MigrationEntry> $migrations
     * @param list<MigrationBatchSummary> $batches
     * @param list<string> $warnings
     */
    public function __construct(
        public MigrationConnection $connection,
        public MigrationRepositoryInfo $repository,
        public MigrationSummary $summary,
        public MigrationScope $scope,
        public MigrationPage $pagination,
        public array $migrations,
        public array $batches,
        public array $warnings,
    ) {
        if ($pagination->count !== count($migrations)) {
            throw new \InvalidArgumentException('Migration pagination count must match the returned migrations.');
        }

        foreach ($migrations as $migration) {
            if (! $migration instanceof MigrationEntry) {
                throw new \InvalidArgumentException('Migration inspection entries must be migration entries.');
            }
        }

        foreach ($batches as $batch) {
            if (! $batch instanceof MigrationBatchSummary) {
                throw new \InvalidArgumentException('Migration batches must be batch summaries.');
            }
        }

        if (count($warnings) > 8) {
            throw new \InvalidArgumentException('Migration inspection has too many warnings.');
        }

        foreach ($warnings as $warning) {
            if (! is_string($warning) || ! Prose::isSafe($warning)) {
                throw new \InvalidArgumentException('Migration warnings must be short prose.');
            }
        }

        $this->schemaVersion = self::SCHEMA_VERSION;
    }
}
