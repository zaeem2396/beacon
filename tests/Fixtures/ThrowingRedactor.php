<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Beacon\Contracts\Redactor;
use Beacon\Data\SafeValue;

final class ThrowingRedactor implements Redactor
{
    public const MESSAGE = 'internal redactor state: secret-detail';

    public function isSensitiveKey(string $key): bool
    {
        throw new \RuntimeException(self::MESSAGE);
    }

    public function isSensitiveValue(mixed $value): bool
    {
        throw new \RuntimeException(self::MESSAGE);
    }

    public function redact(array $data): array
    {
        throw new \RuntimeException(self::MESSAGE);
    }

    public function describe(string $name, mixed $value): SafeValue
    {
        throw new \RuntimeException(self::MESSAGE);
    }
}
