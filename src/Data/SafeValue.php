<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\Contracts\Redactor;

/**
 * A named value (environment variable, configuration entry, header, ...)
 * that is safe to expose. Sensitive values only ever carry the redaction
 * marker. Build instances through {@see Redactor::describe()}.
 */
final readonly class SafeValue extends DataObject
{
    /**
     * @param array<array-key, mixed>|scalar|null $value
     */
    public function __construct(
        public string $name,
        public bool $present,
        public bool $sensitive,
        public array|bool|float|int|string|null $value,
    ) {
        if ($sensitive && $value !== null && $value !== Redactor::REPLACEMENT) {
            throw new \InvalidArgumentException("Sensitive value [{$name}] must be redacted.");
        }
    }
}
