<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Contracts\Data;

/**
 * Base class for Beacon DTOs.
 *
 * Public properties are serialized in declaration order with snake_case keys.
 * Property types must be scalars, arrays, enums, dates or other Data objects.
 */
abstract readonly class DataObject implements Data
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return Normalizer::properties($this);
    }
}
