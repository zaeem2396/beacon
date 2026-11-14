<?php

declare(strict_types=1);

namespace Beacon\Exceptions;

final class InvalidLogQueryException extends \InvalidArgumentException
{
    public static function unavailable(): self
    {
        return new self('The log directory is not available.');
    }

    public static function channel(): self
    {
        return new self('The log channel is not supported.');
    }

    public static function path(): self
    {
        return new self('The log path is not allowed.');
    }

    public static function filter(string $message): self
    {
        return new self($message);
    }
}
