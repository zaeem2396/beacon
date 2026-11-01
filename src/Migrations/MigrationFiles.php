<?php

declare(strict_types=1);

namespace Beacon\Migrations;

/**
 * Lists migration filenames in registered directories.
 *
 * The search is the same shape Laravel uses, `*_*.php` in the directory
 * itself, and it does not recurse. Files are not opened, included, or
 * instantiated. A symlink that resolves outside its directory is skipped.
 */
final class MigrationFiles implements MigrationScanner
{
    public const LIMIT = 2000;

    public function discover(string $basePath, array $directories): DiscoveryResult
    {
        $base = realpath($basePath);
        $files = [];
        $found = [];
        $unreadable = false;
        $outside = false;
        $withheld = false;

        if ($base === false) {
            return new DiscoveryResult([], [], false, true, false, false);
        }

        foreach ($directories as $directory) {
            $resolved = realpath($directory->path);

            if ($resolved === false || ! is_dir($resolved)) {
                $unreadable = true;

                continue;
            }

            $relative = $this->relative($base, $resolved);

            if ($relative === null) {
                $outside = ! $this->inside($base, $resolved) || $outside;
                $withheld = $this->inside($base, $resolved) || $withheld;

                continue;
            }

            $found[$relative] = $relative;
            $matches = glob($resolved . DIRECTORY_SEPARATOR . '*_*.php');

            if ($matches === false) {
                $unreadable = true;

                continue;
            }

            foreach ($matches as $match) {
                $file = $this->file($resolved, $relative, $match, $directory->source);

                if ($file === null) {
                    $withheld = true;

                    continue;
                }

                $files[] = $file;
            }
        }

        usort($files, static fn (DiscoveredFile $left, DiscoveredFile $right): int => [$left->name, $left->relativePath ?? ''] <=> [$right->name, $right->relativePath ?? '']);
        $truncated = count($files) > self::LIMIT;

        if ($truncated) {
            $files = array_slice($files, 0, self::LIMIT);
        }

        $directories = array_values($found);
        sort($directories);

        return new DiscoveryResult($files, $directories, $truncated, $unreadable, $outside, $withheld);
    }

    private function file(string $directory, string $relativeDirectory, string $match, MigrationSourceKind $source): ?DiscoveredFile
    {
        $resolved = realpath($match);

        if ($resolved === false || ! $this->inside($directory, $resolved) || ! is_file($resolved)) {
            return null;
        }

        $filename = basename($resolved);

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_]*\.php$/', $filename) !== 1) {
            return null;
        }

        $name = substr($filename, 0, -4);

        if (! MigrationName::isSafe($name)) {
            return null;
        }

        $relative = $relativeDirectory . '/' . $filename;

        if (strlen($relative) > 220) {
            return null;
        }

        return new DiscoveredFile(
            $name,
            $relative,
            $relativeDirectory,
            $source,
            $this->modifiedAt($resolved),
            MigrationName::filenameAt($name),
        );
    }

    private function modifiedAt(string $path): ?string
    {
        $modified = filemtime($path);

        if ($modified === false) {
            return null;
        }

        return (new \DateTimeImmutable('@' . $modified))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\+00:00');
    }

    private function relative(string $base, string $path): ?string
    {
        if (! $this->inside($base, $path)) {
            return null;
        }

        $relative = str_replace('\\', '/', substr($path, strlen(rtrim($base, DIRECTORY_SEPARATOR)) + 1));

        if ($relative === '' || str_contains($relative, '..') || preg_match('#^[A-Za-z0-9][A-Za-z0-9_./-]*$#', $relative) !== 1) {
            return null;
        }

        return $relative;
    }

    private function inside(string $base, string $path): bool
    {
        $base = rtrim($base, DIRECTORY_SEPARATOR);

        return $path === $base || str_starts_with($path, $base . DIRECTORY_SEPARATOR);
    }
}
