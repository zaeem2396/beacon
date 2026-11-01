<?php

declare(strict_types=1);

namespace Beacon\Risk;

use Beacon\Migrations\DiscoveredFile;

/**
 * Reads migration source without including or executing it.
 */
final class MigrationSourceReader
{
    public const MAX_BYTES = 262144;

    public function read(string $basePath, DiscoveredFile $file): SourceRead
    {
        $relative = $file->relativePath;

        if ($relative === null) {
            return SourceRead::rejected($file->name, 'unreadable');
        }

        $base = realpath($basePath);

        if ($base === false) {
            return SourceRead::rejected($file->name, 'unreadable');
        }

        $candidate = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $resolved = realpath($candidate);

        if ($resolved === false || ! is_file($resolved) || ! $this->inside($base, $resolved)) {
            return SourceRead::rejected($file->name, $resolved !== false && ! $this->inside($base, $resolved) ? 'outside' : 'unreadable');
        }

        $size = filesize($resolved);

        if ($size === false || $size > self::MAX_BYTES) {
            return SourceRead::rejected($file->name, 'too_large');
        }

        $contents = file_get_contents($resolved);

        if (! is_string($contents)) {
            return SourceRead::rejected($file->name, 'unreadable');
        }

        return SourceRead::accepted($file->name, $relative, $contents);
    }

    private function inside(string $base, string $path): bool
    {
        $base = rtrim($base, DIRECTORY_SEPARATOR);

        return $path === $base || str_starts_with($path, $base . DIRECTORY_SEPARATOR);
    }
}
