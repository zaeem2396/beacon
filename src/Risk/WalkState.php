<?php

declare(strict_types=1);

namespace Beacon\Risk;

/**
 * Mutable walk context for one migration method.
 *
 * @internal
 */
final class WalkState
{
    public bool $conditional = false;

    public bool $onNewTable = false;

    public bool $inChunk = false;

    public ?string $blueprint = null;

    public ?string $table = null;

    public bool $sawInclude = false;

    public bool $sawEval = false;

    public bool $sawTrait = false;

    /**
     * @var list<Operation>
     */
    public array $operations = [];

    public function __construct(
        public MigrationPhase $phase,
    ) {
    }
}
