<?php

declare(strict_types=1);

namespace Beacon\Migrations;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Migrations\Migrator;

/**
 * The migration directories Laravel would search.
 *
 * The default application directory is included even when packages add more
 * paths. This does not accept a path from the caller.
 */
final class MigrationPaths
{
    public function __construct(
        private readonly Application $application,
    ) {
    }

    /**
     * @return list<RegisteredDirectory>
     */
    public function directories(): array
    {
        $migrator = $this->application->make('migrator');
        $default = $this->application->databasePath('migrations');
        $directories = [new RegisteredDirectory($default, MigrationSourceKind::Application)];
        $seen = [$this->key($default) => true];

        if (! $migrator instanceof Migrator) {
            return $directories;
        }

        foreach ($migrator->paths() as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            $key = $this->key($path);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $directories[] = new RegisteredDirectory($path, MigrationSourceKind::Registered);
        }

        return $directories;
    }

    private function key(string $path): string
    {
        $resolved = realpath($path);

        return $resolved === false ? $path : $resolved;
    }
}
