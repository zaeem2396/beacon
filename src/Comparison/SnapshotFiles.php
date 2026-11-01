<?php

declare(strict_types=1);

namespace Beacon\Comparison;

use Beacon\Exceptions\InvalidSnapshotException;

/**
 * Reads and writes snapshot files inside storage/beacon/snapshots.
 *
 * Names are a single file segment. Absolute paths and parent traversal are rejected.
 */
final class SnapshotFiles
{
    public function __construct(
        private readonly string $directory,
        private readonly string $storage,
    ) {
    }

    public static function isSafeName(string $name): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,63}\.json$/', $name) === 1;
    }

    public function read(string $name): string
    {
        $path = $this->path($name, false);

        if (! is_file($path)) {
            throw InvalidSnapshotException::unavailable();
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw InvalidSnapshotException::unavailable();
        }

        return $contents;
    }

    public function write(string $name, string $json): void
    {
        $path = $this->path($name, true);

        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw InvalidSnapshotException::unavailable();
        }
    }

    private function path(string $name, bool $create): string
    {
        if (! self::isSafeName($name)) {
            throw InvalidSnapshotException::name();
        }

        if ($create && ! is_dir($this->directory) && ! mkdir($this->directory, 0o775, true) && ! is_dir($this->directory)) {
            throw InvalidSnapshotException::unavailable();
        }

        $storage = realpath($this->storage);
        $directory = realpath($this->directory);

        if ($storage === false || $directory === false || ! $this->inside($storage, $directory)) {
            throw InvalidSnapshotException::unavailable();
        }

        return $directory . DIRECTORY_SEPARATOR . $name;
    }

    private function inside(string $storage, string $directory): bool
    {
        return $directory === $storage || str_starts_with($directory, $storage . DIRECTORY_SEPARATOR);
    }
}
