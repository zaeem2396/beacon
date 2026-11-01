<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Beacon\Migrations\DiscoveryResult;
use Beacon\Migrations\MigrationScanner;

/**
 * Records filesystem discovery and returns no files.
 */
final class RecordingMigrationScanner implements MigrationScanner
{
    public int $calls = 0;

    public function discover(string $basePath, array $directories): DiscoveryResult
    {
        $this->calls++;

        return new DiscoveryResult([], ['database/migrations'], false, false, false, false);
    }
}
