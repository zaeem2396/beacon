<?php

declare(strict_types=1);

namespace Beacon\Risk;

/**
 * Operations found in one parsed migration file.
 */
final readonly class ExtractedMigration
{
    /**
     * @param list<Operation> $operations
     */
    public function __construct(
        public array $operations,
        public bool $parsed,
        public bool $sawInclude,
        public bool $sawEval,
        public bool $sawTrait,
        public ?int $upLine,
        public ?int $downLine,
        public bool $upPresent,
        public bool $downPresent,
    ) {
    }
}
