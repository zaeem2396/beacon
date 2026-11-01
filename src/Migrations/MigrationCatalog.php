<?php

declare(strict_types=1);

namespace Beacon\Migrations;

use Beacon\Data\MigrationBatchSummary;
use Beacon\Data\MigrationEntry;

/**
 * Compares discovered filenames with repository rows.
 *
 * A name found in more than one path stays duplicate. The catalog does not
 * choose one file as the migration that ran. Pending is only used when the
 * repository was read and the name is absent.
 */
final class MigrationCatalog
{
    public const BATCH_LIMIT = 200;

    /**
     * @param list<DiscoveredFile> $files
     * @param array<string, int> $batches repository name => batch
     */
    public function classify(array $files, array $batches, bool $available, MigrationQuery $query): CatalogResult
    {
        $withheld = false;
        $grouped = [];

        foreach ($files as $file) {
            if (! MigrationName::isSafe($file->name)) {
                $withheld = true;

                continue;
            }

            $grouped[$file->name][] = $file;
        }

        $records = [];

        foreach ($batches as $name => $batch) {
            if (! is_string($name) || ! MigrationName::isSafe($name) || $batch < 1) {
                $withheld = true;

                continue;
            }

            $records[$name] = $batch;
        }

        $entries = [];

        foreach ($grouped as $name => $matches) {
            $batch = $available ? ($records[$name] ?? null) : null;
            $status = count($matches) > 1
                ? MigrationStatus::Duplicate
                : $this->single($available, $batch);

            foreach ($matches as $file) {
                $entries[] = $this->file($file, $status, $status === MigrationStatus::Pending ? null : $batch);
            }
        }

        if ($available) {
            foreach ($records as $name => $batch) {
                if (isset($grouped[$name])) {
                    continue;
                }

                $entries[] = new MigrationEntry(
                    $name,
                    MigrationStatus::MissingFile,
                    $batch,
                    null,
                    null,
                    MigrationSourceKind::Repository,
                    false,
                    null,
                    MigrationName::filenameAt($name),
                );
            }
        }

        usort($entries, static fn (MigrationEntry $left, MigrationEntry $right): int => [$left->name, $left->relativePath ?? '', $left->source->value] <=> [$right->name, $right->relativePath ?? '', $right->source->value]);

        $duplicates = $this->duplicateNames($entries) > 0;
        $missing = $this->count($entries, MigrationStatus::MissingFile) > 0;
        $filtered = array_values(array_filter($entries, static fn (MigrationEntry $entry): bool => self::matches($entry, $query)));
        $duplicateNames = $this->duplicateNames($filtered);
        $batches = $this->batches($filtered);

        return new CatalogResult(
            $filtered,
            $batches['batches'],
            $this->files($filtered),
            $this->count($filtered, MigrationStatus::Executed),
            $this->count($filtered, MigrationStatus::Pending),
            $this->count($filtered, MigrationStatus::MissingFile),
            $duplicateNames,
            $duplicates,
            $missing,
            $withheld,
            $batches['truncated'],
        );
    }

    private function single(bool $available, ?int $batch): MigrationStatus
    {
        if (! $available) {
            return MigrationStatus::Unknown;
        }

        return $batch === null ? MigrationStatus::Pending : MigrationStatus::Executed;
    }

    private function file(DiscoveredFile $file, MigrationStatus $status, ?int $batch): MigrationEntry
    {
        return new MigrationEntry(
            $file->name,
            $status,
            $batch,
            $file->name . '.php',
            $file->relativePath,
            $file->source,
            true,
            $file->modifiedAt,
            $file->filenameAt,
        );
    }

    /**
     * @param list<MigrationEntry> $entries
     */
    private function duplicateNames(array $entries): int
    {
        $names = [];

        foreach ($entries as $entry) {
            if ($entry->status === MigrationStatus::Duplicate) {
                $names[$entry->name] = true;
            }
        }

        return count($names);
    }

    /**
     * @param list<MigrationEntry> $entries
     */
    private function count(array $entries, MigrationStatus $status): int
    {
        $count = 0;

        foreach ($entries as $entry) {
            if ($entry->status === $status) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param list<MigrationEntry> $entries
     */
    private function files(array $entries): int
    {
        $count = 0;

        foreach ($entries as $entry) {
            if ($entry->fileExists) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param list<MigrationEntry> $entries
     *
     * @return array{batches: list<MigrationBatchSummary>, truncated: bool}
     */
    private function batches(array $entries): array
    {
        $names = [];

        foreach ($entries as $entry) {
            if ($entry->batch !== null) {
                $names[$entry->batch][$entry->name] = true;
            }
        }

        ksort($names);
        $truncated = count($names) > self::BATCH_LIMIT;

        if ($truncated) {
            $names = array_slice($names, 0, self::BATCH_LIMIT, true);
        }

        $batches = [];

        foreach ($names as $batch => $group) {
            $batches[] = new MigrationBatchSummary($batch, count($group));
        }

        return ['batches' => $batches, 'truncated' => $truncated];
    }

    private static function matches(MigrationEntry $entry, MigrationQuery $query): bool
    {
        if ($query->name !== null && ! str_contains($entry->name, $query->name)) {
            return false;
        }

        if ($query->status !== null && $entry->status !== $query->status) {
            return false;
        }

        if ($query->batch !== null && $entry->batch !== $query->batch) {
            return false;
        }

        if ($query->path !== null) {
            $directory = $entry->relativePath === null ? null : dirname($entry->relativePath);

            if ($directory === '.' || $directory !== $query->path) {
                return false;
            }
        }

        return true;
    }
}
