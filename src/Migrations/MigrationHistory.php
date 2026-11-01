<?php

declare(strict_types=1);

namespace Beacon\Migrations;

interface MigrationHistory
{
    /**
     * @param array<string, mixed> $config
     */
    public function read(array $config, int $timeoutSeconds, string $table): HistoryResult;
}
