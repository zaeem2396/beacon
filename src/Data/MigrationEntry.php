<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Migrations\MigrationName;
use Beacon\Migrations\MigrationSourceKind;
use Beacon\Migrations\MigrationStatus;

/**
 * One discovered migration file or one repository row without a file.
 *
 * filename_at is parsed from the filename. modified_at is the file
 * modification time. Neither is the execution time. The repository batch
 * is the only execution ordering this report returns.
 */
final readonly class MigrationEntry extends DataObject
{
    public function __construct(
        public string $name,
        public MigrationStatus $status,
        public ?int $batch,
        public ?string $filename,
        public ?string $relativePath,
        public MigrationSourceKind $source,
        public bool $fileExists,
        public ?string $modifiedAt,
        public ?string $filenameAt,
    ) {
        if (! MigrationName::isSafe($name)) {
            throw new \InvalidArgumentException('Migration name must be a plain identifier.');
        }

        if ($batch !== null && ($batch < 1 || $batch > 1000000)) {
            throw new \InvalidArgumentException('Migration batch is out of range.');
        }

        if ($filename !== null && $filename !== $name . '.php') {
            throw new \InvalidArgumentException('Migration filename must match the migration name.');
        }

        if ($relativePath !== null && ! self::relative($relativePath)) {
            throw new \InvalidArgumentException('Migration path must be relative.');
        }

        if ($fileExists && ($filename === null || $source === MigrationSourceKind::Repository)) {
            throw new \InvalidArgumentException('A migration file needs a filename and a filesystem source.');
        }

        if (! $fileExists && $source !== MigrationSourceKind::Repository) {
            throw new \InvalidArgumentException('A missing migration file is a repository record.');
        }

        if ($status === MigrationStatus::MissingFile && $fileExists) {
            throw new \InvalidArgumentException('A missing-file migration cannot have a file.');
        }

        if ($status === MigrationStatus::Pending && $batch !== null) {
            throw new \InvalidArgumentException('A pending migration has no batch.');
        }

        if ($status === MigrationStatus::Executed && ($batch === null || ! $fileExists)) {
            throw new \InvalidArgumentException('An executed migration needs a file and a batch.');
        }

        foreach ([$modifiedAt, $filenameAt] as $stamp) {
            if ($stamp !== null && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $stamp) !== 1) {
                throw new \InvalidArgumentException('Migration timestamps must be UTC.');
            }
        }

        if (! $fileExists && $modifiedAt !== null) {
            throw new \InvalidArgumentException('A missing migration file has no modification time.');
        }
    }

    private static function relative(string $path): bool
    {
        return strlen($path) <= 220
            && preg_match('#^[A-Za-z0-9][A-Za-z0-9_./-]*\.php$#', $path) === 1
            && ! str_contains($path, '..')
            && ! str_contains($path, '//')
            && ! str_starts_with($path, '/');
    }
}
