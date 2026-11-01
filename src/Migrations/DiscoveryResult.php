<?php

declare(strict_types=1);

namespace Beacon\Migrations;

/**
 * Filenames found under registered migration directories.
 *
 * directories are relative paths a caller may filter on. Absolute paths are
 * not included.
 */
final readonly class DiscoveryResult
{
    /**
     * @param list<DiscoveredFile> $files
     * @param list<string> $directories
     */
    public function __construct(
        public array $files,
        public array $directories,
        public bool $truncated,
        public bool $unreadable,
        public bool $outside,
        public bool $withheld,
    ) {
    }
}
