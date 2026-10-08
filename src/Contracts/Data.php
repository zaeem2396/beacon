<?php

declare(strict_types=1);

namespace Beacon\Contracts;

/**
 * A typed, serializable value object that crosses Beacon's data boundary.
 *
 * Data objects intentionally do not implement JsonSerializable: output must
 * be produced through {@see \Beacon\Core\Payload}, which normalizes and
 * redacts it.
 */
interface Data
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
