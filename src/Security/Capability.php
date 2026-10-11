<?php

declare(strict_types=1);

namespace Beacon\Security;

/**
 * A named permission required to use a Beacon feature, e.g. "routes:read".
 */
final readonly class Capability implements \Stringable
{
    public function __construct(
        public string $name,
        public AccessMode $mode,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("Invalid capability name [{$name}].");
        }
    }

    public function __toString(): string
    {
        return "{$this->name}:{$this->mode->value}";
    }

    public static function read(string $name): self
    {
        return new self($name, AccessMode::Read);
    }

    public function isReadOnly(): bool
    {
        return $this->mode === AccessMode::Read;
    }
}
