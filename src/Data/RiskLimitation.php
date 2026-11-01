<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Migrations\MigrationName;
use Beacon\Schema\Prose;

/**
 * A file or inspection limit. An unparsed migration is not treated as safe.
 */
final readonly class RiskLimitation extends DataObject
{
    public function __construct(
        public ?string $migration,
        public string $message,
    ) {
        if ($migration !== null && ! MigrationName::isSafe($migration)) {
            throw new \InvalidArgumentException('Risk limitation migration name must be a plain identifier.');
        }

        if (! Prose::isSafe($message)) {
            throw new \InvalidArgumentException('Risk limitation message must be short prose.');
        }
    }
}
