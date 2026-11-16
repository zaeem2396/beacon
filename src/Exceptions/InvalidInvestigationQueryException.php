<?php

declare(strict_types=1);

namespace Beacon\Exceptions;

final class InvalidInvestigationQueryException extends \InvalidArgumentException
{
    public static function filter(string $message): self
    {
        return new self($message);
    }

    public static function scope(string $message): self
    {
        return new self($message);
    }
}
