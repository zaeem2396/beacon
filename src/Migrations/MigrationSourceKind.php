<?php

declare(strict_types=1);

namespace Beacon\Migrations;

/**
 * Where a migration entry came from.
 *
 * application is the framework migration directory. registered is an
 * additional path given to the migrator. repository is a history row
 * with no discovered file.
 */
enum MigrationSourceKind: string
{
    case Application = 'application';
    case Registered = 'registered';
    case Repository = 'repository';
}
