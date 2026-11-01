<?php

declare(strict_types=1);

namespace Beacon\Risk;

/**
 * One recognized operation. It is not a database command to run.
 */
final readonly class Operation
{
    public function __construct(
        public OperationKind $kind,
        public MigrationPhase $phase,
        public int $line,
        public int $endLine,
        public string $snippet,
        public ?string $table = null,
        public ?string $column = null,
        public ?string $columnType = null,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public bool $nullable = false,
        public bool $hasDefault = false,
        public bool $change = false,
        public bool $unique = false,
        public bool $index = false,
        public bool $foreign = false,
        public bool $cascadeDelete = false,
        public bool $cascadeUpdate = false,
        public bool $conditional = false,
        public bool $onNewTable = false,
        public bool $chunked = false,
        public bool $rowByRow = false,
        public ?string $sqlEffect = null,
    ) {
    }
}
