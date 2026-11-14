<?php

declare(strict_types=1);

namespace Beacon\Exceptions;

final class InvalidRequestQueryException extends \InvalidArgumentException
{
    public static function filter(string $message): self
    {
        return new self($message);
    }

    public static function disabled(): self
    {
        return new self('Request collection is disabled.');
    }

    public static function unavailable(): self
    {
        return new self('Request storage is unavailable.');
    }
}
