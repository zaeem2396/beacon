<?php

declare(strict_types=1);

namespace Beacon\Migrations;

/**
 * How one migration name relates to the files and the repository.
 *
 * pending means a file has no repository record. It does not mean the
 * migration failed. unknown means the repository was not read, so the file
 * is not described as pending.
 */
enum MigrationStatus: string
{
    case Executed = 'executed';
    case Pending = 'pending';
    case MissingFile = 'missing_file';
    case Duplicate = 'duplicate';
    case Unknown = 'unknown';
}
