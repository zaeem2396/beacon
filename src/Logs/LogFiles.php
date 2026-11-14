<?php

declare(strict_types=1);

namespace Beacon\Logs;

use Beacon\Exceptions\InvalidLogQueryException;
use Beacon\Health\SafeName;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * Resolves only configured file-based log channels under storage/logs.
 */
final class LogFiles
{
    public const MAX_BYTES = 524288;

    public const MAX_FILES = 14;

    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
    ) {
    }

    /**
     * @return list<array{channel: string, path: string}>
     */
    public function files(?string $channel = null): array
    {
        $root = $this->root();
        $names = $channel === null ? $this->configuredChannels() : [$this->requireChannel($channel)];
        $files = [];

        foreach ($names as $name) {
            foreach ($this->pathsFor($name, $root) as $path) {
                $files[] = ['channel' => $name, 'path' => $path];
            }
        }

        return $files;
    }

    public function root(): string
    {
        $root = realpath($this->app->storagePath('logs'));

        if ($root === false || ! is_dir($root)) {
            throw InvalidLogQueryException::unavailable();
        }

        return $root;
    }

    /**
     * @return list<string>
     */
    private function configuredChannels(): array
    {
        $default = $this->config->get('logging.default');
        $channels = [];

        if (is_string($default) && SafeName::isIdentifier($default)) {
            $channels[] = $default;
        }

        $stack = $this->config->get('logging.channels.' . (is_string($default) ? $default : ''));

        if (is_array($stack) && ($stack['driver'] ?? null) === 'stack' && is_array($stack['channels'] ?? null)) {
            foreach ($stack['channels'] as $child) {
                if (is_string($child) && SafeName::isIdentifier($child)) {
                    $channels[] = $child;
                }
            }
        }

        return array_values(array_unique($channels));
    }

    private function requireChannel(string $channel): string
    {
        if (! SafeName::isIdentifier($channel)) {
            throw InvalidLogQueryException::channel();
        }

        $definition = $this->config->get('logging.channels.' . $channel);

        if (! is_array($definition)) {
            throw InvalidLogQueryException::channel();
        }

        return $channel;
    }

    /**
     * @return list<string>
     */
    private function pathsFor(string $channel, string $root): array
    {
        $definition = $this->config->get('logging.channels.' . $channel);

        if (! is_array($definition)) {
            return [];
        }

        $driver = $definition['driver'] ?? null;

        if ($driver === 'stack' && is_array($definition['channels'] ?? null)) {
            $paths = [];

            foreach ($definition['channels'] as $child) {
                if (is_string($child) && SafeName::isIdentifier($child)) {
                    $paths = array_merge($paths, $this->pathsFor($child, $root));
                }
            }

            return $paths;
        }

        if ($driver === 'single') {
            return $this->confine($definition['path'] ?? null, $root);
        }

        if ($driver === 'daily') {
            $path = $definition['path'] ?? null;

            if (! is_string($path) || $path === '') {
                return [];
            }

            $base = pathinfo($path, PATHINFO_DIRNAME) . '/' . pathinfo($path, PATHINFO_FILENAME);
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            $pattern = $base . '-*.' . ($extension !== '' ? $extension : 'log');
            $matches = glob($pattern) ?: [];
            rsort($matches);
            $paths = [];

            foreach (array_slice($matches, 0, self::MAX_FILES) as $match) {
                $paths = array_merge($paths, $this->confine($match, $root));
            }

            $current = $this->confine($path, $root);

            return array_values(array_unique(array_merge($current, $paths)));
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function confine(mixed $path, string $root): array
    {
        if (! is_string($path) || $path === '') {
            return [];
        }

        $resolved = realpath($path);

        if ($resolved === false || ! is_file($resolved)) {
            return [];
        }

        $prefix = $root . DIRECTORY_SEPARATOR;

        if (! str_starts_with($resolved, $prefix) && $resolved !== $root) {
            throw InvalidLogQueryException::path();
        }

        return [$resolved];
    }
}
