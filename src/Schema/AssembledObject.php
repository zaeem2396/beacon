<?php

declare(strict_types=1);

namespace Beacon\Schema;

use Beacon\Data\SchemaObject;

/**
 * One assembled object, plus whether any metadata was withheld.
 */
final readonly class AssembledObject
{
    public function __construct(
        public ?SchemaObject $object,
        public bool $withheld,
    ) {
    }
}
