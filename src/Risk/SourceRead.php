<?php

declare(strict_types=1);

namespace Beacon\Risk;

/**
 * The bytes of one migration file, or the reason they were not read.
 */
final readonly class SourceRead
{
    private function __construct(
        public string $migration,
        public ?string $relativePath,
        public ?string $contents,
        public ?string $problem,
    ) {
    }

    public static function accepted(string $migration, string $relativePath, string $contents): self
    {
        return new self($migration, $relativePath, $contents, null);
    }

    public static function rejected(string $migration, string $problem): self
    {
        return new self($migration, null, null, $problem);
    }

    public function available(): bool
    {
        return $this->contents !== null && $this->relativePath !== null;
    }
}
