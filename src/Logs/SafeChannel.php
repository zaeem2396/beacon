<?php

declare(strict_types=1);

namespace Beacon\Logs;

use Beacon\Health\SafeName;

final class SafeChannel
{
    public static function name(?string $value): ?string
    {
        return is_string($value) && SafeName::isIdentifier($value) ? $value : null;
    }
}
