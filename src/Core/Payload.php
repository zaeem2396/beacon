<?php

declare(strict_types=1);

namespace Beacon\Core;

use Beacon\Contracts\Data;
use Beacon\Contracts\Redactor;
use Beacon\Data\Normalizer;

/**
 * The only form in which Beacon data leaves the core (MCP, CLI JSON, ...).
 *
 * A payload can only be created from Data, which is normalized and then
 * redacted, so sensitive values that slip through an inspector are still
 * removed before output.
 */
final readonly class Payload
{
    /**
     * @param array<array-key, mixed> $data
     */
    private function __construct(
        private array $data,
    ) {
    }

    public static function fromData(Data $data, Redactor $redactor): self
    {
        $normalized = Normalizer::normalize($data);

        return new self(is_array($normalized) ? $redactor->redact($normalized) : []);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode(
            $this->data,
            $flags | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }
}
