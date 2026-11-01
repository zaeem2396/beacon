<?php

declare(strict_types=1);

namespace Beacon\Migrations;

interface MigrationScanner
{
    /**
     * @param list<RegisteredDirectory> $directories
     */
    public function discover(string $basePath, array $directories): DiscoveryResult;
}
