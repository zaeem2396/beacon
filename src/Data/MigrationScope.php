<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Migrations\MigrationStatus;

/**
 * The filters that produced the migration counts and page.
 *
 * path is a relative registered directory, never an absolute filesystem path.
 */
final readonly class MigrationScope extends DataObject
{
    public function __construct(
        public bool $filtered,
        public ?string $name,
        public ?MigrationStatus $status,
        public ?int $batch,
        public ?string $path,
    ) {
        if ($name !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9_]{0,63}$/', $name) !== 1) {
            throw new \InvalidArgumentException('Migration name filter must be a plain token.');
        }

        if ($batch !== null && ($batch < 1 || $batch > 1000000)) {
            throw new \InvalidArgumentException('Migration batch filter is out of range.');
        }

        if ($path !== null && (strlen($path) > 180 || preg_match('#^[A-Za-z0-9][A-Za-z0-9_./-]*$#', $path) !== 1 || str_contains($path, '..'))) {
            throw new \InvalidArgumentException('Migration path filter must be a relative registered path.');
        }

        $active = $name !== null || $status !== null || $batch !== null || $path !== null;

        if ($filtered !== $active) {
            throw new \InvalidArgumentException('Migration scope filtered flag must match the filters.');
        }
    }
}
