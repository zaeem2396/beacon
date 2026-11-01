<?php

declare(strict_types=1);

namespace Beacon\Risk;

use Beacon\Migrations\MigrationStatus;

/**
 * The operations and optional schema facts for one migration file.
 */
final readonly class MigrationFacts
{
    /**
     * @param list<Operation> $operations
     * @param array<string, TableSnapshot> $tables
     */
    public function __construct(
        public string $name,
        public string $relativePath,
        public MigrationStatus $status,
        public array $operations,
        public bool $sawInclude,
        public bool $sawEval,
        public bool $sawTrait,
        public ?int $upLine,
        public ?int $downLine,
        public bool $upPresent,
        public bool $downPresent,
        public array $tables,
        public bool $enriched,
    ) {
    }

    public function table(?string $name): ?TableSnapshot
    {
        if ($name === null || ! $this->enriched) {
            return null;
        }

        return $this->tables[$name] ?? null;
    }

    public function createdInUp(?string $table): bool
    {
        if ($table === null) {
            return false;
        }

        foreach ($this->operations as $operation) {
            if ($operation->phase === MigrationPhase::Up && $operation->kind === OperationKind::CreateTable && $operation->table === $table) {
                return true;
            }
        }

        return false;
    }
}
