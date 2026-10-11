<?php

declare(strict_types=1);

namespace Beacon\Contracts;

use Beacon\Data\SafeValue;

interface Redactor
{
    public const REPLACEMENT = '[REDACTED]';

    /**
     * Whether a key (env name, dotted config path, header, array key, ...)
     * names a sensitive value.
     */
    public function isSensitiveKey(string $key): bool;

    /**
     * Whether a value looks like a secret regardless of its key.
     */
    public function isSensitiveValue(mixed $value): bool;

    /**
     * Recursively replace sensitive values in a normalized array.
     *
     * Booleans and nulls are preserved, since they only convey presence.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    public function redact(array $data): array;

    /**
     * Describe a named value safely: presence, sensitivity and the value
     * itself only when it is not sensitive.
     */
    public function describe(string $name, mixed $value): SafeValue;
}
