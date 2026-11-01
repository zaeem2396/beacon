<?php

declare(strict_types=1);

namespace Beacon\Migrations;

/**
 * A migration directory registered by the Laravel application.
 *
 * The path is whatever the framework stored. It is not used until it has
 * been resolved and confirmed to stay inside the application base.
 */
final readonly class RegisteredDirectory
{
    public function __construct(
        public string $path,
        public MigrationSourceKind $source,
    ) {
    }
}
